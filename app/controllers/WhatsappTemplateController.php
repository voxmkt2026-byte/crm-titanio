<?php
/**
 * app/controllers/WhatsappTemplateController.php
 * CRUD simples de templates de mensagem para WhatsApp (Fase 7 - auditoria
 * UX). Restrito a quem tem "settings.manage" (mesma permissão usada por
 * SettingController), já que fica pendurado como sub-tela de Configurações.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/WhatsappTemplate.php';

class WhatsappTemplateController extends Controller
{
    private WhatsappTemplate $model;
    private const CATEGORIES = ['geral', 'primeiro_contato', 'follow_up', 'documentacao', 'proposta', 'pos_venda'];

    public function __construct()
    {
        $this->model = new WhatsappTemplate();
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

        $this->view('settings/whatsapp-templates', [
            'pageTitle' => 'Templates de WhatsApp',
            'templates' => $this->model->all('name ASC'),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $name = trim((string) $this->input('name', ''));
        $content = trim((string) $this->input('content', ''));
        $category = $this->category();

        if ($name === '' || $content === '') {
            flash('error', 'Informe o nome e o conteúdo do template.');
            $this->redirect('configuracoes/whatsapp-templates');
            return;
        }

        $this->model->create($name, $content, $category, true);
        log_activity('whatsapp_template_criado', 'Template de WhatsApp "' . $name . '" criado.');

        flash('success', 'Template criado com sucesso.');
        $this->redirect('configuracoes/whatsapp-templates');
    }

    public function update(string $id): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $name = trim((string) $this->input('name', ''));
        $content = trim((string) $this->input('content', ''));
        $active = $this->input('active') ? true : false;
        $category = $this->category();

        if ($name === '' || $content === '') {
            flash('error', 'Informe o nome e o conteúdo do template.');
            $this->redirect('configuracoes/whatsapp-templates');
            return;
        }

        $this->model->updateTemplate((int) $id, $name, $content, $category, $active);
        log_activity('whatsapp_template_atualizado', 'Template de WhatsApp #' . $id . ' atualizado.');

        flash('success', 'Template atualizado com sucesso.');
        $this->redirect('configuracoes/whatsapp-templates');
    }

    public function destroy(string $id): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $this->model->delete((int) $id);
        log_activity('whatsapp_template_excluido', 'Template de WhatsApp #' . $id . ' excluído.');

        flash('success', 'Template excluído com sucesso.');
        $this->redirect('configuracoes/whatsapp-templates');
    }

    /**
     * Lista JSON dos templates ativos, usada pelo <select> no modal de envio
     * de WhatsApp (ver app/views/leads/show.php + public/assets/js/app.js).
     */
    public function listJson(): void
    {
        $this->requireLogin();

        $items = array_map(function ($t) {
            return [
                'id'      => (int) $t['id'],
                'name'    => $t['name'],
                'category'=> $t['category'] ?? 'geral',
                'content' => $t['content'],
            ];
        }, $this->model->allActive());

        $this->json(['items' => $items]);
    }

    private function category(): string
    {
        $category = (string) $this->input('category', 'geral');
        return in_array($category, self::CATEGORIES, true) ? $category : 'geral';
    }
}
