<?php
/**
 * app/controllers/WhatsappController.php
 * Endpoint AJAX para envio de mensagens WhatsApp a partir do perfil do lead
 * (ver botão "Enviar WhatsApp" em app/views/leads/show.php), usando a
 * WhatsApp Cloud API via app/core/WhatsappClient.php.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/WhatsappClient.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/LeadHistory.php';

class WhatsappController extends Controller
{
    private Lead $leadModel;
    private LeadHistory $historyModel;

    public function __construct()
    {
        $this->leadModel = new Lead();
        $this->historyModel = new LeadHistory();
    }

    /** POST /leads/{id}/whatsapp — envia mensagem de texto livre para o lead. */
    public function send(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $leadId = (int) $id;
        $lead = $this->leadModel->find($leadId);

        if (!$lead) {
            $this->json(['success' => false, 'message' => 'Lead não encontrado.'], 404);
            return;
        }

        $phone = $lead['whatsapp'] ?: $lead['phone'];
        if (!$phone) {
            $this->json(['success' => false, 'message' => 'Este lead não possui telefone/WhatsApp cadastrado.'], 422);
            return;
        }

        $message = trim((string) $this->input('message', ''));
        if ($message === '') {
            $this->json(['success' => false, 'message' => 'Digite uma mensagem antes de enviar.'], 422);
            return;
        }

        $client = WhatsappClient::fromSettings();

        if (!$client->isConfigured()) {
            $this->json([
                'success' => false,
                'message' => 'WhatsApp Cloud API ainda não configurada. Preencha o Token e o Phone Number ID em Configurações > Integrações.',
            ], 422);
            return;
        }

        $result = $client->sendTextMessage($phone, $message);

        if ($result['success']) {
            $this->historyModel->add($leadId, Auth::id(), 'whatsapp', 'Mensagem enviada via WhatsApp: "' . $message . '"');
            log_activity('whatsapp_enviado', 'Mensagem WhatsApp enviada ao lead #' . $leadId . '.');
        }

        $this->json($result, $result['success'] ? 200 : 502);
    }
}
