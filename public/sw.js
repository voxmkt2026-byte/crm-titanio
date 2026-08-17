/**
 * public/sw.js
 * Service Worker do Titanium CRM. Três funções:
 * 1) Tornar o app instalável ("Adicionar à tela inicial") — ver manifest.json
 *    e o registro em public/assets/js/app.js::initServiceWorker().
 * 2) Ponte de notificação já existente: quando o polling de notificações
 *    (app.js) encontra algo novo, manda um postMessage tipo NOTIFY pra cá,
 *    que exibe uma notificação do sistema mesmo com a aba em segundo plano
 *    (funciona enquanto o navegador está aberto).
 * 3) Push de verdade (evento 'push'): o navegador recebe mesmo com o CRM
 *    fechado. O registro/assinatura do lado do cliente já está pronto (ver
 *    initPushNotifications() em app.js), mas o ENVIO a partir do servidor
 *    ainda não está implementado — precisa de chaves VAPID e da assinatura
 *    do protocolo Web Push, que não foram geradas nesta etapa.
 *
 * IMPORTANTE — v2: este Service Worker NÃO INTERCEPTA MAIS NENHUMA
 * REQUISIÇÃO (sem 'fetch' handler). A v1 fazia cache-first de /assets/,
 * o que causou CSS/JS "grudado" (o navegador continuava servindo a versão
 * antiga em cache mesmo depois de um novo deploy). Isso era redundante:
 * o helpers.php::asset() já resolve cache-busting sozinho, acrescentando
 * ?v=<data de modificação do arquivo> na URL — o cache HTTP normal do
 * navegador já dá conta disso sem precisar de Service Worker no meio do
 * caminho. O bloco de 'activate' abaixo existe só para APAGAR o cache que
 * a v1 deixou em quem já tinha instalado o app antes desta correção.
 */

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (keys) {
                // Remove QUALQUER cache de versões anteriores deste Service Worker
                // (ex: 'tc-static-v1' da v1, que guardava CSS/JS antigos).
                return Promise.all(keys.map(function (key) { return caches.delete(key); }));
            })
            .then(function () { return self.clients.claim(); })
    );
});

// ---- Ponte já existente: notificação disparada pelo polling em app.js ----
self.addEventListener('message', function (e) {
    if (e.data && e.data.type === 'NOTIFY') {
        self.registration.showNotification(e.data.title || 'Titanium CRM', {
            body: e.data.body || '',
            icon: '/assets/img/icon-192.png',
            badge: '/assets/img/icon-192.png',
            data: { url: e.data.url || './' },
        });
    }
});

// ---- Push de verdade (funciona com o navegador fechado, quando o envio do servidor existir) ----
self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'Titanium CRM', body: event.data ? event.data.text() : '' };
    }
    var title = data.title || 'Titanium CRM';
    var options = {
        body: data.body || '',
        icon: '/assets/img/icon-192.png',
        badge: '/assets/img/icon-192.png',
        data: { url: data.url || '/dashboard' },
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    var target = (e.notification.data && e.notification.data.url) || './';
    e.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (var i = 0; i < list.length; i++) {
                if ('focus' in list[i]) {
                    list[i].navigate(target);
                    return list[i].focus();
                }
            }
            return clients.openWindow(target);
        })
    );
});
