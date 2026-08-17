<?php
/**
 * app/controllers/ChatController.php
 * Chat interno entre usuários, dividido por departamento. Módulo novo e
 * independente do restante do sistema (não mexe em leads/pipeline).
 *
 * Sem WebSockets/Node (restrição da hospedagem compartilhada Hostinger):
 * o front-end (public/assets/js/app.js, initChat()) faz polling AJAX em
 * GET /chat/salas/{id}/mensagens?since_id=... a cada poucos segundos
 * enquanto a tela de chat está aberta, e consulta GET /chat/nao-lidas com
 * intervalo maior para o badge da sidebar nas demais telas. O polling é
 * incremental (since_id), nunca recarrega a conversa inteira a cada
 * requisição.
 *
 * Segurança: toda ação valida login, CSRF (nas que alteram dado) e
 * pertencimento à sala (ChatRoom::isMember) no back-end — nunca confia em
 * esconder algo só no front-end. Todo texto de mensagem é gravado como
 * texto puro e escapado na saída (ver e()/escapeHtml no app.js), nunca
 * renderizado como HTML.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/ChatRoom.php';
require_once APP_PATH . '/models/ChatMessage.php';
require_once APP_PATH . '/models/ChatDepartment.php';
require_once APP_PATH . '/models/User.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/Task.php';
require_once APP_PATH . '/models/TaskWatcher.php';
require_once APP_PATH . '/models/Setting.php';
require_once APP_PATH . '/models/WhatsappTemplate.php';
require_once APP_PATH . '/services/GeminiService.php';

class ChatController extends Controller
{
    private ChatRoom $roomModel;
    private ChatMessage $messageModel;
    private User $userModel;

    public function __construct()
    {
        $this->roomModel = new ChatRoom();
        $this->messageModel = new ChatMessage();
        $this->userModel = new User();
    }

    /** GET /chat */
    public function index(): void
    {
        $this->requireLogin();
        $userId = Auth::id();

        $this->selfHeal($userId);

        $rooms = $this->roomModel->roomsForUser($userId);

        $activeRoomId = (int) $this->input('sala', 0);
        $activeRoom = $this->pickActiveRoom($rooms, $activeRoomId);

        $messages = [];
        if ($activeRoom) {
            $raw = $this->messageModel->listForRoom((int) $activeRoom['id'], null, null, 40);
            $messages = array_map([$this, 'formatMessage'], $raw);
            $this->roomModel->markRead((int) $activeRoom['id'], $userId);
        }

        $this->view('chat/index', [
            'pageTitle'            => 'Chat Interno',
            'rooms'                => $rooms,
            'activeRoom'           => $activeRoom,
            'activeRoomId'         => $activeRoom ? (int) $activeRoom['id'] : 0,
            'activeRoomMembers'    => $activeRoom ? $this->roomModel->membersOf((int) $activeRoom['id']) : [],
            'messages'             => $messages,
            'currentUserId'        => $userId,
            'canModerateGlobal'    => Auth::can('chat.moderate'),
            'canCreateRoom'        => Auth::can('chat.create_room'),
            'canModerateActiveRoom' => $activeRoom ? $this->canModerate((int) $activeRoom['id'], $userId) : false,
            'canManageCustomGroup' => $activeRoom ? $this->canManageCustomGroup($activeRoom, $userId) : false,
            'isActiveCustomGroup'  => $activeRoom ? $this->isCustomGroup($activeRoom) : false,
        ]);
    }

    /** GET /chat/salas/{id}/mensagens — polling incremental (since_id) ou "carregar mais antigas" (before_id) */
    public function messages(string $id): void
    {
        $this->requireLogin();
        $roomId = (int) $id;
        $userId = Auth::id();

        if (!$this->roomModel->isMember($roomId, $userId)) {
            $this->json(['success' => false, 'message' => 'Você não participa desta sala.'], 403);
            return;
        }

        $sinceId = $this->input('since_id') !== null && $this->input('since_id') !== '' ? (int) $this->input('since_id') : null;
        $beforeId = $this->input('before_id') !== null && $this->input('before_id') !== '' ? (int) $this->input('before_id') : null;

        $raw = $this->messageModel->listForRoom($roomId, $sinceId, $beforeId, 40);
        $messages = array_map([$this, 'formatMessage'], $raw);

        $this->json([
            'success'      => true,
            'messages'     => $messages,
            'unread_total' => $this->roomModel->unreadCountForUser($userId),
        ]);
    }

    /** POST /chat/salas/{id}/mensagens */
    public function send(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $roomId = (int) $id;
        $userId = Auth::id();

        $room = $this->roomModel->find($roomId);
        if (!$room) {
            $this->json(['success' => false, 'message' => 'Sala não encontrada.'], 404);
            return;
        }
        if (!$this->roomModel->isMember($roomId, $userId)) {
            $this->json(['success' => false, 'message' => 'Você não participa desta sala.'], 403);
            return;
        }

        $content = trim((string) $this->input('content', ''));
        if ($content === '') {
            $this->json(['success' => false, 'message' => 'Digite uma mensagem.'], 422);
            return;
        }
        if (mb_strlen($content) > 2000) {
            $content = mb_substr($content, 0, 2000);
        }

        if (strpos($content, '/') === 0) {
            $result = $this->handleCommand($room, $roomId, $userId, $content);
            $this->json($result);
            return;
        }

        $msgId = $this->messageModel->send($roomId, $userId, $content);
        $msg = $this->messageModel->find($msgId);

        $this->json([
            'success' => true,
            'message' => $this->formatMessage($msg),
        ]);
    }

    /** POST /chat/salas/{id}/ler */
    public function markRead(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $roomId = (int) $id;
        $userId = Auth::id();

        if (!$this->roomModel->isMember($roomId, $userId)) {
            $this->json(['success' => false, 'message' => 'Você não participa desta sala.'], 403);
            return;
        }

        $this->roomModel->markRead($roomId, $userId);
        $this->json(['success' => true, 'unread_total' => $this->roomModel->unreadCountForUser($userId)]);
    }

    /** POST /chat/salas — cria sala de grupo customizada */
    public function createRoom(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        if (!Auth::can('chat.create_room')) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para criar salas.'], 403);
            return;
        }

        $name = trim((string) $this->input('name', ''));
        $memberIds = $this->input('member_ids', []);
        if (!is_array($memberIds)) {
            $memberIds = [];
        }
        $memberIds = array_map('intval', $memberIds);

        if ($name === '' || mb_strlen($name) < 2) {
            $this->json(['success' => false, 'message' => 'Informe um nome válido para a sala (mínimo 2 caracteres).'], 422);
            return;
        }

        $userId = Auth::id();
        $roomId = $this->roomModel->createGroup($name, $userId, $memberIds);
        $this->messageModel->system($roomId, $this->currentUserName() . ' criou a sala "' . $name . '".');

        log_activity('chat_sala_criada', 'Sala de grupo "' . $name . '" (#' . $roomId . ') criada no chat interno.');

        $this->json(['success' => true, 'room_id' => $roomId]);
    }

    /** POST /chat/salas/lead/{id} — cria ou abre a sala privada de um lead. */
    public function createLeadRoom(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        if (!Auth::can('chat.create_room')) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para criar salas.'], 403);
            return;
        }

        $leadId = (int) $id;
        $lead = (new Lead())->find($leadId);
        if (!$lead) {
            $this->json(['success' => false, 'message' => 'Lead não encontrado.'], 404);
            return;
        }

        $userId = Auth::id();
        if (!Auth::hasRole(['admin', 'supervisor']) && (int) ($lead['assigned_to'] ?? 0) !== $userId) {
            $this->json(['success' => false, 'message' => 'Você não tem acesso a este lead.'], 403);
            return;
        }

        $memberIds = $this->activeMemberIds($this->input('member_ids', []), $userId);
        $room = $this->roomModel->findLeadRoom($leadId);

        if ($room) {
            $roomId = (int) $room['id'];
            if (!$this->roomModel->isMember($roomId, $userId)) {
                $this->json([
                    'success' => false,
                    'message' => 'Esta sala é privada. Peça a um administrador da sala para convidar você.',
                ], 403);
                return;
            }

            if (!empty($memberIds)) {
                if (!$this->canModerate($roomId, $userId)) {
                    $this->json(['success' => false, 'message' => 'Somente administradores da sala podem convidar pessoas.'], 403);
                    return;
                }
                $invited = $this->inviteMembersToRoom($roomId, $memberIds);
                if (!empty($invited)) {
                    $this->messageModel->system(
                        $roomId,
                        $this->currentUserName() . ' convidou ' . implode(', ', $invited) . ' para a sala do lead.'
                    );
                }
            }

            $this->json(['success' => true, 'room_id' => $roomId, 'created' => false]);
            return;
        }

        // O responsável pelo lead é incluído por padrão, além dos colegas
        // selecionados. Assim, a conversa acompanha o atendimento do lead.
        $assignedTo = (int) ($lead['assigned_to'] ?? 0);
        if ($assignedTo > 0 && $assignedTo !== $userId) {
            $memberIds[] = $assignedTo;
        }
        $memberIds = $this->activeMemberIds($memberIds, $userId);

        $displayName = trim((string) ($lead['name'] ?? ''));
        if ($displayName === '') {
            $displayName = (string) ($lead['lead_code'] ?? ('#' . $leadId));
        }
        $roomName = 'Lead · ' . mb_substr($displayName, 0, 105);

        $created = true;
        try {
            $roomId = $this->roomModel->createLeadRoom($leadId, $roomName, $userId, $memberIds);
        } catch (Throwable $e) {
            // Em dois cliques simultâneos, a chave única de lead_id pode ter
            // criado a sala no outro request. Reutiliza a sala encontrada.
            $existing = $this->roomModel->findLeadRoom($leadId);
            if (!$existing) {
                throw $e;
            }
            $roomId = (int) $existing['id'];
            if (!$this->roomModel->isMember($roomId, $userId)) {
                $this->json([
                    'success' => false,
                    'message' => 'A sala foi aberta por outra pessoa e é privada. Peça um convite ao administrador da sala.',
                ], 403);
                return;
            }
            $created = false;
        }

        if ($created) {
            $this->messageModel->system(
                $roomId,
                $this->currentUserName() . ' abriu uma sala privada para o lead ' . $displayName . '.'
            );
            log_activity('chat_sala_lead_criada', 'Sala privada #' . $roomId . ' aberta para o lead #' . $leadId . '.');
        }

        $this->json(['success' => true, 'room_id' => $roomId, 'created' => $created]);
    }

    /** POST /chat/salas/tarefa/{id} — cria ou abre a conversa privada de uma tarefa. */
    public function createTaskRoom(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        if (!Auth::can('chat.create_room')) {
            $this->json(['success' => false, 'message' => 'Você não tem permissão para abrir conversas de tarefa.'], 403);
            return;
        }

        $taskId = (int) $id;
        $taskModel = new Task();
        $task = $taskModel->find($taskId);
        if (!$task) {
            $this->json(['success' => false, 'message' => 'Tarefa não encontrada.'], 404);
            return;
        }

        $userId = Auth::id();
        $watcherModel = new TaskWatcher();
        $canAccessTask = Auth::can('tasks.view_all')
            || (int) ($task['creator_id'] ?? 0) === $userId
            || (int) ($task['assigned_to'] ?? 0) === $userId
            || $watcherModel->isWatching($taskId, $userId);
        if (!$canAccessTask) {
            $this->json(['success' => false, 'message' => 'Você não tem acesso a esta tarefa.'], 403);
            return;
        }

        $room = $this->roomModel->findTaskRoom($taskId);
        if ($room) {
            $roomId = (int) $room['id'];
            if (!$this->roomModel->isMember($roomId, $userId)) {
                $this->json([
                    'success' => false,
                    'message' => 'Esta conversa é privada. Peça a um administrador da sala para convidar você.',
                ], 403);
                return;
            }

            $this->json(['success' => true, 'room_id' => $roomId, 'created' => false]);
            return;
        }

        // A conversa nasce com quem acompanha a demanda: criador,
        // responsável e watchers. O usuário que a abriu é administrador
        // local da sala, independentemente de sua função global no CRM.
        $memberIds = array_merge(
            [(int) ($task['creator_id'] ?? 0), (int) ($task['assigned_to'] ?? 0)],
            $watcherModel->userIdsForTask($taskId)
        );
        $memberIds = $this->activeMemberIds($memberIds, $userId);
        $roomName = 'Tarefa · ' . mb_substr(trim((string) $task['title']), 0, 104);

        $created = true;
        try {
            $roomId = $this->roomModel->createTaskRoom($taskId, $roomName, $userId, $memberIds);
        } catch (Throwable $e) {
            // A chave única de task_id também protege dois cliques simultâneos.
            $existing = $this->roomModel->findTaskRoom($taskId);
            if (!$existing) {
                throw $e;
            }
            $roomId = (int) $existing['id'];
            if (!$this->roomModel->isMember($roomId, $userId)) {
                $this->json([
                    'success' => false,
                    'message' => 'A conversa foi aberta por outra pessoa e é privada. Peça um convite ao administrador da sala.',
                ], 403);
                return;
            }
            $created = false;
        }

        if ($created) {
            $this->messageModel->system($roomId, $this->currentUserName() . ' abriu a conversa privada da tarefa "' . $task['title'] . '".');
            log_activity('chat_sala_tarefa_criada', 'Sala privada #' . $roomId . ' aberta para a tarefa #' . $taskId . '.');
        }

        $this->json(['success' => true, 'room_id' => $roomId, 'created' => $created]);
    }

    /** POST /chat/salas/{id}/convidar — adiciona pessoas a uma sala de grupo. */
    public function inviteMembers(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $roomId = (int) $id;
        $userId = Auth::id();
        $room = $this->roomModel->find($roomId);
        if (!$room || $room['type'] !== 'grupo' || !$this->roomModel->isMember($roomId, $userId)) {
            $this->json(['success' => false, 'message' => 'Sala não encontrada ou sem acesso.'], 404);
            return;
        }
        if (!$this->canModerate($roomId, $userId)) {
            $this->json(['success' => false, 'message' => 'Somente administradores da sala podem convidar pessoas.'], 403);
            return;
        }

        $memberIds = $this->activeMemberIds($this->input('member_ids', []), 0);
        if (empty($memberIds)) {
            $this->json(['success' => false, 'message' => 'Selecione ao menos uma pessoa para convidar.'], 422);
            return;
        }

        $invited = $this->inviteMembersToRoom($roomId, $memberIds);
        if (!empty($invited)) {
            $this->messageModel->system(
                $roomId,
                $this->currentUserName() . ' convidou ' . implode(', ', $invited) . ' para a sala.'
            );
            log_activity('chat_membro_adicionado', implode(', ', $invited) . ' convidado(a)(s) para a sala #' . $roomId . '.');
        }

        $this->json([
            'success'      => true,
            'count'        => count($invited),
            'member_count' => count($this->roomModel->membersOf($roomId)),
            'message'      => empty($invited) ? 'As pessoas selecionadas já participam da sala.' : 'Convite enviado com sucesso.',
        ]);
    }

    /** POST /chat/salas/{id}/atualizar — nome, descrição e foto de grupo. */
    public function updateGroup(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $roomId = (int) $id;
        $room = $this->managedCustomGroup($roomId, (int) Auth::id());
        if (!$room) {
            $this->redirect('chat?sala=' . $roomId);
            return;
        }

        $name = trim((string) $this->input('name', ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            flash('error', 'O nome do grupo precisa ter entre 2 e 120 caracteres.');
            $this->redirect('chat?sala=' . $roomId);
            return;
        }

        $description = trim((string) $this->input('description', ''));
        $description = $description === '' ? null : mb_substr($description, 0, 1000);
        $image = $this->input('remove_image') === '1' ? null : ($room['image_filename'] ?? null);

        try {
            $uploaded = $this->storeGroupImage();
            if ($uploaded !== null) {
                $image = $uploaded;
            }
        } catch (RuntimeException $exception) {
            flash('error', $exception->getMessage());
            $this->redirect('chat?sala=' . $roomId);
            return;
        }

        $this->roomModel->updateCustomGroup($roomId, $name, $description, $image);
        $this->messageModel->system($roomId, $this->currentUserName() . ' atualizou as informações do grupo.');
        log_activity('chat_grupo_atualizado', 'Grupo "' . $name . '" (#' . $roomId . ') atualizado.');
        flash('success', 'Informações do grupo atualizadas.');
        $this->redirect('chat?sala=' . $roomId);
    }

    /** POST /chat/salas/{id}/membros/{userId}/remover. */
    public function removeGroupMember(string $id, string $memberId): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $roomId = (int) $id;
        $userId = (int) Auth::id();
        $targetId = (int) $memberId;
        $room = $this->managedCustomGroup($roomId, $userId);
        if (!$room) {
            $this->redirect('chat?sala=' . $roomId);
            return;
        }
        if ($targetId === $userId) {
            flash('error', 'Para sair do grupo, use a opção “Sair da sala”.');
            $this->redirect('chat?sala=' . $roomId);
            return;
        }

        $targetMembership = $this->roomModel->getMember($roomId, $targetId);
        $target = $this->userModel->find($targetId);
        if (!$targetMembership || !$target) {
            flash('error', 'Participante não encontrado neste grupo.');
            $this->redirect('chat?sala=' . $roomId);
            return;
        }
        if ($this->roomModel->memberCount($roomId) <= 1) {
            flash('error', 'Não é possível deixar um grupo sem participantes. Exclua o grupo se ele não for mais necessário.');
            $this->redirect('chat?sala=' . $roomId);
            return;
        }
        if (in_array($targetMembership['role'], ['admin_sala', 'moderador'], true) && $this->roomModel->adminCount($roomId) <= 1) {
            flash('error', 'Não é possível remover o último administrador do grupo. Exclua o grupo ou mantenha outro administrador.');
            $this->redirect('chat?sala=' . $roomId);
            return;
        }

        $this->roomModel->removeMember($roomId, $targetId);
        $this->messageModel->system($roomId, $target['name'] . ' foi removido(a) do grupo por ' . $this->currentUserName() . '.');
        log_activity('chat_membro_removido', $target['name'] . ' removido(a) do grupo #' . $roomId . '.');
        flash('success', $target['name'] . ' foi removido(a) do grupo.');
        $this->redirect('chat?sala=' . $roomId);
    }

    /** POST /chat/salas/{id}/excluir — apaga somente grupos personalizados. */
    public function deleteGroup(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $roomId = (int) $id;
        $room = $this->managedCustomGroup($roomId, (int) Auth::id());
        if (!$room) {
            $this->redirect('chat');
            return;
        }

        $name = (string) ($room['name'] ?? ('Grupo #' . $roomId));
        if (!$this->roomModel->deleteCustomGroup($roomId)) {
            flash('error', 'Não foi possível excluir este grupo.');
            $this->redirect('chat?sala=' . $roomId);
            return;
        }

        log_activity('chat_grupo_excluido', 'Grupo "' . $name . '" (#' . $roomId . ') excluído.');
        flash('success', 'Grupo excluído com todas as suas mensagens e participantes.');
        $this->redirect('chat');
    }

    /** POST /chat/direto/{userId} — cria ou retorna a DM existente e redireciona pra ela */
    public function direct(string $userId): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $targetId = (int) $userId;
        $myId = Auth::id();

        if ($targetId === $myId) {
            flash('error', 'Você não pode iniciar uma conversa consigo mesmo.');
            $this->redirect('chat');
            return;
        }

        $target = $this->userModel->find($targetId);
        if (!$target || (int) $target['active'] !== 1) {
            flash('error', 'Usuário não encontrado ou inativo.');
            $this->redirect('chat');
            return;
        }

        $roomId = $this->roomModel->findOrCreateDirect($myId, $targetId);
        $this->redirect('chat?sala=' . $roomId);
    }

    /** POST /chat/mensagens/{id}/apagar */
    public function deleteMessage(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $messageId = (int) $id;
        $userId = Auth::id();

        $message = $this->messageModel->find($messageId);
        if (!$message || $message['deleted_at'] !== null) {
            $this->json(['success' => false, 'message' => 'Mensagem não encontrada.'], 404);
            return;
        }

        $roomId = (int) $message['room_id'];
        if (!$this->roomModel->isMember($roomId, $userId)) {
            $this->json(['success' => false, 'message' => 'Você não participa desta sala.'], 403);
            return;
        }

        $isOwner = (int) ($message['user_id'] ?? 0) === $userId;
        if (!$isOwner && !$this->canModerate($roomId, $userId)) {
            $this->json(['success' => false, 'message' => 'Você só pode apagar suas próprias mensagens.'], 403);
            return;
        }

        $this->messageModel->softDelete($messageId);
        $this->json(['success' => true, 'id' => $messageId]);
    }

    /** POST /chat/salas/{id}/silenciar */
    public function toggleMute(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $roomId = (int) $id;
        $userId = Auth::id();

        if (!$this->roomModel->isMember($roomId, $userId)) {
            $this->json(['success' => false, 'message' => 'Você não participa desta sala.'], 403);
            return;
        }

        $this->roomModel->toggleMute($roomId, $userId);
        $member = $this->roomModel->getMember($roomId, $userId);

        $this->json(['success' => true, 'muted' => (bool) ($member['muted'] ?? false)]);
    }

    /** POST /chat/salas/{id}/sair — sai de um grupo (não permitido para departamento/DM) */
    public function leaveRoom(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $roomId = (int) $id;
        $userId = Auth::id();

        $room = $this->roomModel->find($roomId);
        if (!$room || !$this->roomModel->isMember($roomId, $userId)) {
            flash('error', 'Sala não encontrada.');
            $this->redirect('chat');
            return;
        }
        if ($room['type'] !== 'grupo') {
            flash('error', 'Só é possível sair de salas de grupo.');
            $this->redirect('chat?sala=' . $roomId);
            return;
        }

        $myMembership = $this->roomModel->getMember($roomId, $userId);
        if ($this->isCustomGroup($room) && $this->roomModel->memberCount($roomId) <= 1) {
            flash('error', 'Você é o último participante. Exclua o grupo em “Gerenciar grupo” ou adicione outra pessoa antes de sair.');
            $this->redirect('chat?sala=' . $roomId);
            return;
        }
        if ($this->isCustomGroup($room)
            && $myMembership
            && in_array($myMembership['role'], ['admin_sala', 'moderador'], true)
            && $this->roomModel->adminCount($roomId) <= 1) {
            flash('error', 'Você é o último administrador. Exclua o grupo ou mantenha outro administrador antes de sair.');
            $this->redirect('chat?sala=' . $roomId);
            return;
        }

        $this->roomModel->removeMember($roomId, $userId);
        $this->messageModel->system($roomId, $this->currentUserName() . ' saiu da sala.');

        flash('success', 'Você saiu da sala.');
        $this->redirect('chat');
    }

    /** GET /chat/salas/{id}/membros */
    public function members(string $id): void
    {
        $this->requireLogin();
        $roomId = (int) $id;
        $userId = Auth::id();

        if (!$this->roomModel->isMember($roomId, $userId)) {
            $this->json(['success' => false, 'message' => 'Você não participa desta sala.'], 403);
            return;
        }

        $members = array_map(function (array $m) {
            return [
                'user_id'  => (int) $m['user_id'],
                'name'     => $m['name'],
                'email'    => $m['email'],
                'role'     => $m['role'],
                'avatar'   => $m['avatar'] ?? null,
                'initials' => $this->initials($m['name']),
            ];
        }, $this->roomModel->membersOf($roomId));

        $this->json(['success' => true, 'members' => $members]);
    }

    /** GET /chat/usuarios/buscar?q=... — typeahead para iniciar uma DM */
    public function searchUsers(): void
    {
        $this->requireLogin();
        $term = trim((string) $this->input('q', ''));
        if (mb_strlen($term) < 2) {
            $this->json(['success' => true, 'users' => []]);
            return;
        }

        $users = array_map(function (array $u) {
            return [
                'id'       => (int) $u['id'],
                'name'     => $u['name'],
                'email'    => $u['email'],
                'avatar'   => $u['avatar'] ?? null,
                'initials' => $this->initials($u['name']),
            ];
        }, $this->userModel->searchActive($term, Auth::id(), 10));

        $this->json(['success' => true, 'users' => $users]);
    }

    /** GET /chat/nao-lidas — polling leve para o badge da sidebar (fora da tela de chat) */
    public function unreadTotal(): void
    {
        $this->requireLogin();
        $this->json(['count' => $this->roomModel->unreadCountForUser(Auth::id())]);
    }

    // ------------------------------------------------------------------
    // Comandos de barra ("/comando"), interpretados dentro de send()
    // ------------------------------------------------------------------

    private function handleCommand(array $room, int $roomId, int $userId, string $text): array
    {
        $parts = preg_split('/\s+/', trim($text));
        $command = strtolower(array_shift($parts));
        $arg = trim(implode(' ', $parts));
        $arg = ltrim($arg, '@');

        switch ($command) {
            case '/ajuda':
                return [
                    'success'   => true,
                    'ephemeral' => true,
                    'message'   => "Comandos disponíveis:\n"
                        . "/ajuda — mostra esta lista de comandos\n"
                        . "/lead nome ou telefone — consulta dados de um lead permitido\n"
                        . "/empresa — mostra os dados institucionais cadastrados\n"
                        . "/cartas — lista os modelos de mensagem disponíveis\n"
                        . "/ia pergunta — consulta CRM e Wiki dentro da sua permissão\n"
                        . "/abordagem contexto — cria um script comercial Titanium\n"
                        . "/objecao texto — sugere resposta para uma objeção\n"
                        . "@nome — menciona um membro da equipe\n"
                        . "/limpar — apaga todas as mensagens da sala (moderador+)\n"
                        . "/adicionar @usuario — adiciona um membro a um grupo (moderador+)\n"
                        . "/remover @usuario — remove um membro de um grupo (moderador+)",
                ];

            case '/ia':
            case '/abordagem':
            case '/objecao':
                if (mb_strlen($arg) < 1) {
                    return ['success'=>false,'ephemeral'=>true,'message'=>'Escreva o contexto depois do comando.'];
                }
                $purpose=$command==='/abordagem'?'approach':($command==='/objecao'?'objection':'assistant');
                $result=(new GeminiService())->ask($arg,$userId,(string)(Auth::user()['role']??''),$purpose);
                return ['success'=>(bool)$result['success'],'ephemeral'=>true,'message'=>$result['text']??$result['message']??'Não foi possível responder.'];

            case '/lead':
                if (mb_strlen($arg) < 1) {
                    return ['success' => false, 'ephemeral' => true, 'message' => 'Digite /lead seguido do nome, telefone, e-mail ou código do lead.'];
                }
                $onlyAssignedTo = Auth::hasRole(['admin', 'supervisor']) ? null : $userId;
                $leads = (new Lead())->quickSearch($arg, $onlyAssignedTo, 5);
                if (!$leads) {
                    return ['success' => false, 'ephemeral' => true, 'message' => 'Nenhum lead encontrado dentro do seu acesso.'];
                }
                $lines = ['Leads encontrados:'];
                foreach ($leads as $lead) {
                    $contact = format_phone($lead['whatsapp'] ?: $lead['phone']);
                    $lines[] = '• ' . ($lead['name'] ?: 'Lead #' . $lead['id']) . ' | ' . ($lead['lead_code'] ?: '#' . $lead['id'])
                        . ' | ' . status_label($lead['status']) . ($contact ? ' | ' . $contact : '');
                }
                return ['success' => true, 'ephemeral' => true, 'message' => implode("\n", $lines)];

            case '/empresa':
                $settings = (new Setting())->allAsMap();
                $company = $settings['company_name'] ?? COMPANY_NAME;
                $cnpj = $settings['report_cnpj'] ?? 'não cadastrado';
                $phone = $settings['report_phone'] ?? ($settings['company_phone'] ?? 'não cadastrado');
                return ['success' => true, 'ephemeral' => true, 'message' => "Dados da empresa:\n• Nome: {$company}\n• CNPJ: {$cnpj}\n• Contato: {$phone}"];

            case '/cartas':
                try {
                    $templates = (new WhatsappTemplate())->allActive();
                } catch (Throwable $e) {
                    $templates = [];
                }
                if (!$templates) {
                    return ['success' => true, 'ephemeral' => true, 'message' => 'Nenhum modelo de mensagem ativo foi cadastrado.'];
                }
                $names = array_map(static fn(array $template): string => '• ' . $template['name'], $templates);
                return ['success' => true, 'ephemeral' => true, 'message' => "Modelos disponíveis:\n" . implode("\n", $names)];

            case '/limpar':
                if (!$this->canModerate($roomId, $userId)) {
                    return ['success' => false, 'ephemeral' => true, 'message' => 'Somente moderadores podem limpar a conversa desta sala.'];
                }
                $count = $this->messageModel->clearRoom($roomId);
                $this->messageModel->system($roomId, $this->currentUserName() . ' limpou a conversa desta sala.');
                log_activity('chat_sala_limpa', 'Sala #' . $roomId . ' limpa (' . $count . ' mensagem(ns) apagada(s)) por comando /limpar.');
                return ['success' => true, 'command' => 'limpar', 'message' => 'Conversa limpa com sucesso.'];

            case '/adicionar':
            case '/remover':
                if ($room['type'] !== 'grupo') {
                    return ['success' => false, 'ephemeral' => true, 'message' => 'Este comando só pode ser usado em salas de grupo.'];
                }
                if (!$this->canModerate($roomId, $userId)) {
                    return ['success' => false, 'ephemeral' => true, 'message' => 'Somente moderadores podem gerenciar membros desta sala.'];
                }
                if ($arg === '') {
                    return ['success' => false, 'ephemeral' => true, 'message' => 'Informe o nome ou e-mail do usuário. Ex: ' . $command . ' @maria'];
                }

                $target = $this->userModel->findActiveByNameOrEmail($arg);
                if (!$target) {
                    return ['success' => false, 'ephemeral' => true, 'message' => 'Usuário "' . $arg . '" não encontrado (use o nome completo ou e-mail).'];
                }

                if ($command === '/adicionar') {
                    if ($this->roomModel->isMember($roomId, (int) $target['id'])) {
                        return ['success' => false, 'ephemeral' => true, 'message' => $target['name'] . ' já participa desta sala.'];
                    }
                    $this->roomModel->addMember($roomId, (int) $target['id'], 'membro', true);
                    $this->messageModel->system($roomId, $target['name'] . ' foi adicionado(a) à sala por ' . $this->currentUserName() . '.');
                    log_activity('chat_membro_adicionado', $target['name'] . ' adicionado(a) à sala #' . $roomId . '.');
                    return ['success' => true, 'command' => 'adicionar', 'message' => $target['name'] . ' adicionado(a) com sucesso.'];
                }

                if ((int) $target['id'] === $userId) {
                    return ['success' => false, 'ephemeral' => true, 'message' => 'Use "Sair da sala" para se remover.'];
                }
                if (!$this->roomModel->isMember($roomId, (int) $target['id'])) {
                    return ['success' => false, 'ephemeral' => true, 'message' => $target['name'] . ' não participa desta sala.'];
                }
                $this->roomModel->removeMember($roomId, (int) $target['id']);
                $this->messageModel->system($roomId, $target['name'] . ' foi removido(a) da sala por ' . $this->currentUserName() . '.');
                log_activity('chat_membro_removido', $target['name'] . ' removido(a) da sala #' . $roomId . '.');
                return ['success' => true, 'command' => 'remover', 'message' => $target['name'] . ' removido(a) com sucesso.'];

            default:
                return ['success' => false, 'ephemeral' => true, 'message' => 'Comando não reconhecido: ' . $command . '. Digite /ajuda para ver os comandos disponíveis.'];
        }
    }

    // ------------------------------------------------------------------
    // Helpers privados
    // ------------------------------------------------------------------

    /** Garante que o usuário logado está nas salas de departamento certas (Geral + a sua). */
    private function selfHeal(int $userId): void
    {
        try {
            $user = $this->userModel->find($userId);
            $this->roomModel->syncUserDepartmentMembership($userId, isset($user['department_id']) ? (int) $user['department_id'] ?: null : null);
        } catch (Throwable $e) {
            error_log('ChatController::selfHeal - falha ao sincronizar salas (rode database/sql/migration_chat.sql): ' . $e->getMessage());
        }
    }

    private function pickActiveRoom(array $rooms, int $requestedId): ?array
    {
        if ($requestedId > 0) {
            foreach ($rooms as $room) {
                if ((int) $room['id'] === $requestedId) {
                    return $room;
                }
            }
        }
        return $rooms[0] ?? null;
    }

    /** true se o usuário pode moderar ESTA sala (permissão global OU papel local de moderação) */
    private function canModerate(int $roomId, int $userId): bool
    {
        if (Auth::can('chat.moderate')) {
            return true;
        }
        $member = $this->roomModel->getMember($roomId, $userId);
        return $member && in_array($member['role'], ['moderador', 'admin_sala'], true);
    }

    /** Grupo sem vínculo automático com departamento, lead ou tarefa. */
    private function isCustomGroup(array $room): bool
    {
        return ($room['type'] ?? '') === 'grupo'
            && empty($room['lead_id'])
            && empty($room['task_id'])
            && empty($room['department_id']);
    }

    private function canManageCustomGroup(array $room, int $userId): bool
    {
        return $this->isCustomGroup($room)
            && $this->roomModel->isMember((int) $room['id'], $userId)
            && $this->canModerate((int) $room['id'], $userId);
    }

    /** Retorna o grupo somente quando o usuário tem gestão local/global. */
    private function managedCustomGroup(int $roomId, int $userId): ?array
    {
        $room = $this->roomModel->find($roomId);
        if (!$room || !$this->isCustomGroup($room)) {
            flash('error', 'Esta ação está disponível apenas para grupos personalizados.');
            return null;
        }
        if (!$this->roomModel->isMember($roomId, $userId) || !$this->canManageCustomGroup($room, $userId)) {
            flash('error', 'Somente administradores do grupo podem realizar esta ação.');
            return null;
        }
        return $room;
    }

    /** @throws RuntimeException */
    private function storeGroupImage(): ?string
    {
        if (empty($_FILES['group_image']) || $_FILES['group_image']['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($_FILES['group_image']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['group_image']['tmp_name'])) {
            throw new RuntimeException('Não foi possível receber a foto do grupo.');
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = (string) mime_content_type($_FILES['group_image']['tmp_name']);
        if (!isset($allowed[$mime]) || (int) $_FILES['group_image']['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('A foto do grupo deve ser JPG, PNG ou WEBP com até 2 MB.');
        }
        if (!is_dir(UPLOADS_PATH)) {
            @mkdir(UPLOADS_PATH, 0755, true);
        }
        if (!is_dir(UPLOADS_PATH) || !is_writable(UPLOADS_PATH)) {
            throw new RuntimeException('A pasta de uploads não possui permissão de escrita.');
        }

        $filename = 'chat_group_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        $destination = UPLOADS_PATH . '/' . $filename;
        if (!move_uploaded_file($_FILES['group_image']['tmp_name'], $destination) || !is_file($destination) || filesize($destination) === 0) {
            throw new RuntimeException('Não foi possível salvar a foto do grupo.');
        }
        return $filename;
    }

    /** @return int[] IDs válidos de usuários ativos, sem duplicidade. */
    private function activeMemberIds($ids, int $excludeId): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $valid = [];
        foreach (array_unique(array_map('intval', $ids)) as $memberId) {
            if ($memberId <= 0 || $memberId === $excludeId) {
                continue;
            }
            $user = $this->userModel->find($memberId);
            if ($user && (int) ($user['active'] ?? 0) === 1) {
                $valid[] = $memberId;
            }
        }
        return $valid;
    }

    /** @param int[] $memberIds @return string[] nomes efetivamente convidados */
    private function inviteMembersToRoom(int $roomId, array $memberIds): array
    {
        $invited = [];
        foreach ($memberIds as $memberId) {
            if ($this->roomModel->isMember($roomId, $memberId)) {
                continue;
            }
            $user = $this->userModel->find($memberId);
            if (!$user || (int) ($user['active'] ?? 0) !== 1) {
                continue;
            }
            $this->roomModel->addMember($roomId, $memberId, 'membro', false);
            $invited[] = $user['name'];
        }
        return $invited;
    }

    private function currentUserName(): string
    {
        $user = Auth::user();
        return $user['name'] ?? 'Alguém';
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (empty($parts) || $parts[0] === '') {
            return '?';
        }
        $initials = substr($parts[0], 0, 1);
        if (isset($parts[1])) {
            $initials .= substr($parts[1], 0, 1);
        }
        return strtoupper($initials);
    }

    private function formatMessage(array $m): array
    {
        $deleted = !empty($m['deleted_at']);
        return [
            'id'         => (int) $m['id'],
            'room_id'    => (int) $m['room_id'],
            'user_id'    => $m['user_id'] !== null ? (int) $m['user_id'] : null,
            'user_name'  => $m['user_name'] ?? 'Sistema',
            'avatar'     => $m['user_avatar'] ?? null,
            'initials'   => $this->initials($m['user_name'] ?? 'Sistema'),
            'type'       => $m['type'],
            'content'    => $deleted ? null : $m['content'],
            'deleted'    => $deleted,
            'edited'     => !empty($m['edited_at']),
            'mine'       => (int) ($m['user_id'] ?? 0) === Auth::id(),
            'created_at' => $m['created_at'],
            'time'       => date('H:i', strtotime($m['created_at'])),
            'date_label' => chat_date_label($m['created_at']),
        ];
    }
}
