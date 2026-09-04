<?php
/**
 * app/views/profile/index.php
 * Edição do próprio perfil: nome, e-mail, avatar e senha.
 */
$roleLabels = ['admin' => 'Administrador', 'supervisor' => 'Supervisor', 'consultor' => 'Consultor'];
?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="POST" action="<?= e(url('perfil/update')) ?>" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <div class="tc-card mb-3">
                <div class="tc-card-header">Dados pessoais</div>
                <div class="tc-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome completo</label>
                            <input type="text" name="name" class="form-control" value="<?= e($user['name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Foto de perfil (avatar)</label>
                            <input type="file" name="avatar" class="form-control" accept="image/png,image/jpeg,image/webp">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Papel</label>
                            <input type="text" class="form-control" value="<?= e($roleLabels[$user['role'] ?? ''] ?? '') ?>" disabled>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tc-card mb-3">
                <div class="tc-card-header">Alterar senha</div>
                <div class="tc-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nova senha</label>
                            <input type="password" name="password" class="form-control" minlength="6" placeholder="Deixe em branco para manter a atual">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirmar nova senha</label>
                            <input type="password" name="password_confirmation" class="form-control" minlength="6">
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-tc-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i> Salvar Alterações</button>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="tc-card">
            <div class="tc-card-body text-center">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= e($user['avatar']) ?>" alt="Avatar" style="width:96px;height:96px;border-radius:50%;object-fit:cover;" onerror="this.style.display='none';document.getElementById('tcAvatarFallback').classList.remove('d-none');">
                    <div id="tcAvatarFallback" class="tc-user-avatar mx-auto d-none" style="width:96px;height:96px;font-size:2rem;">
                        <?= e(strtoupper(substr($user['name'] ?? '?', 0, 1))) ?>
                    </div>
                <?php else: ?>
                    <div class="tc-user-avatar mx-auto" style="width:96px;height:96px;font-size:2rem;">
                        <?= e(strtoupper(substr($user['name'] ?? '?', 0, 1))) ?>
                    </div>
                <?php endif; ?>
                <div class="fw-semibold mt-3"><?= e($user['name'] ?? '') ?></div>
                <div class="text-muted" style="font-size:0.8rem;"><?= e($user['email'] ?? '') ?></div>
            </div>
        </div>

        <?php if (!empty($captureForms)): ?>
        <div class="tc-card mt-3">
            <div class="tc-card-header"><i class="fa-solid fa-qrcode me-1"></i> Meu link de captação</div>
            <div class="tc-card-body text-center">
                <?php if (count($captureForms) > 1): ?>
                    <select id="tcMyLinkForm" class="form-select form-select-sm mb-3">
                        <?php foreach ($captureForms as $f): ?>
                            <option value="<?= e(url('f/' . $f['slug']) . '?consultor=' . (int) $user['id']) ?>"><?= e($f['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <div id="tcMyLinkQr" class="d-inline-block p-2 bg-white rounded"></div>
                <div class="input-group input-group-sm mt-3">
                    <input type="text" id="tcMyLinkInput" class="form-control tc-form-link-input" readonly
                           value="<?= e(url('f/' . $captureForms[0]['slug']) . '?consultor=' . (int) $user['id']) ?>">
                    <button type="button" class="btn btn-outline-secondary" id="tcMyLinkCopy" title="Copiar link"><i class="fa-solid fa-copy"></i></button>
                </div>
                <p class="text-muted mt-2 mb-0" style="font-size:0.72rem;">Compartilhe este link ou o QR Code — qualquer lead que entrar por ele já chega atribuído a você.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($captureForms)): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var qrEl = document.getElementById('tcMyLinkQr');
    var input = document.getElementById('tcMyLinkInput');
    var select = document.getElementById('tcMyLinkForm');
    var copyBtn = document.getElementById('tcMyLinkCopy');
    if (!qrEl || typeof QRCode === 'undefined') return;

    var qr = new QRCode(qrEl, { text: input.value, width: 160, height: 160, colorDark: '#1e3a5f', colorLight: '#ffffff' });

    function updateQr(url) {
        input.value = url;
        qr.clear();
        qr.makeCode(url);
    }

    if (select) {
        select.addEventListener('change', function () { updateQr(select.value); });
    }
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            navigator.clipboard.writeText(input.value).then(function () {
                if (typeof Toastify !== 'undefined') {
                    Toastify({ text: 'Link copiado!', duration: 1800, gravity: 'top', position: 'right', style: { background: '#16a34a', borderRadius: '0.5rem' } }).showToast();
                }
            });
        });
    }
});
</script>
<?php endif; ?>
