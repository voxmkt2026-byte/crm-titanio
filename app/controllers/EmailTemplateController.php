<?php
/** CRUD dos modelos de e-mail reutilizáveis do CRM. */
require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/EmailTemplate.php';

class EmailTemplateController extends Controller
{
    private EmailTemplate $model;
    private const CATEGORIES = ['geral', 'primeiro_contato', 'follow_up', 'documentacao', 'proposta', 'pos_venda'];

    public function __construct()
    {
        $this->model = new EmailTemplate();
    }

    private function requireAdmin(): void
    {
        $this->requireLogin();
        if (!Auth::hasRole(['admin']) || !Auth::can('settings.manage')) {
            flash('error', 'Acesso restrito a administradores.');
            $this->redirect('dashboard');
        }
    }

    public function index(): void
    {
        $this->requireAdmin();
        $this->view('settings/email-templates', [
            'pageTitle' => 'Templates de E-mail',
            'templates' => $this->model->all('category ASC, name ASC'),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();
        $data = $this->validatedInput();
        if ($data === null) {
            $this->redirect('configuracoes/email-templates');
            return;
        }
        $this->model->create($data['name'], $data['category'], $data['subject'], $data['content']);
        log_activity('email_template_criado', 'Template de e-mail "' . $data['name'] . '" criado.');
        flash('success', 'Template de e-mail criado com sucesso.');
        $this->redirect('configuracoes/email-templates');
    }

    public function update(string $id): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();
        $data = $this->validatedInput();
        if ($data === null) {
            $this->redirect('configuracoes/email-templates');
            return;
        }
        $this->model->updateTemplate((int) $id, $data['name'], $data['category'], $data['subject'], $data['content'], (bool) $data['active']);
        log_activity('email_template_atualizado', 'Template de e-mail #' . (int) $id . ' atualizado.');
        flash('success', 'Template de e-mail atualizado com sucesso.');
        $this->redirect('configuracoes/email-templates');
    }

    public function destroy(string $id): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();
        $this->model->delete((int) $id);
        log_activity('email_template_excluido', 'Template de e-mail #' . (int) $id . ' excluído.');
        flash('success', 'Template de e-mail excluído com sucesso.');
        $this->redirect('configuracoes/email-templates');
    }

    public function listJson(): void
    {
        $this->requireLogin();
        $items = array_map(static fn(array $template): array => [
            'id' => (int) $template['id'], 'name' => $template['name'], 'category' => $template['category'],
            'subject' => $template['subject'], 'content' => $template['content'],
        ], $this->model->allActive());
        $this->json(['items' => $items]);
    }

    private function validatedInput(): ?array
    {
        $name = mb_substr(trim((string) $this->input('name', '')), 0, 120);
        $subject = mb_substr(trim((string) $this->input('subject', '')), 0, 255);
        $content = mb_substr(trim((string) $this->input('content', '')), 0, 12000);
        $category = (string) $this->input('category', 'geral');
        if ($name === '' || $subject === '' || $content === '') {
            flash('error', 'Informe nome, assunto e conteúdo do template.');
            return null;
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'geral';
        }
        return ['name' => $name, 'category' => $category, 'subject' => $subject, 'content' => $content, 'active' => $this->input('active') ? 1 : 0];
    }
}
