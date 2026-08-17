<?php
/**
 * app/controllers/NotificationController.php
 * Notificações internas (sino do topbar). Sem websockets/Node: o front-end
 * consulta GET /notifications/unread a cada 30-60s via setInterval
 * (ver public/assets/js/app.js) e marca como lida via POST /notifications/{id}/read.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/Notification.php';

class NotificationController extends Controller
{
    private Notification $model;

    public function __construct()
    {
        $this->model = new Notification();
    }

    /** GET /notifications/unread — JSON com contador e lista das últimas notificações. */
    public function unread(): void
    {
        $this->requireLogin();

        $userId = Auth::id();
        $this->model->generateGoalReachedAlerts();

        $items = array_map(function (array $n) {
            return [
                'id'         => (int) $n['id'],
                'title'      => $n['title'],
                'message'    => $n['message'],
                'link'       => $n['link'] ? url($n['link']) : null,
                'read'       => $n['read_at'] !== null,
                'created_at' => $n['created_at'],
                'time_ago'   => time_ago($n['created_at']),
            ];
        }, $this->model->forUser($userId, 15));

        $this->json([
            'count' => $this->model->unreadCount($userId),
            'items' => $items,
        ]);
    }

    /** POST /notifications/{id}/read */
    public function markRead(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $this->model->markRead((int) $id, Auth::id());
        $this->json(['success' => true]);
    }

    /** POST /notifications/read-all */
    public function markAllRead(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $this->model->markAllRead(Auth::id());
        $this->json(['success' => true]);
    }
}
