<?php
/**
 * app/views/partials/_chat_theme_picker.php
 * Seletor de tema/cor dos balões, papel de parede e tipografia do chat,
 * reaproveitado pelo Chat interno e pelo Atendimento WhatsApp (mesmas
 * classes CSS .tc-chat-*, ver public/assets/css/app.css). Preferência é só
 * do navegador (localStorage), lida por initChatTheme() em
 * public/assets/js/app.js — não precisa de rota nem de coluna no banco.
 */
?>
<div class="dropdown">
    <button class="tc-icon-btn" type="button" data-bs-toggle="dropdown" title="Aparência da conversa">
        <i class="fa-solid fa-palette"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-end shadow-sm tc-chat-theme-menu">
        <strong style="font-size:0.78rem;">Cor dos balões</strong>
        <div class="tc-chat-theme-swatches">
            <button type="button" class="tc-chat-theme-swatch" data-theme="whatsapp" style="background:#dcf8c6" title="WhatsApp"></button>
            <button type="button" class="tc-chat-theme-swatch" data-theme="azul" style="background:#dbeafe" title="Azul"></button>
            <button type="button" class="tc-chat-theme-swatch" data-theme="roxo" style="background:#ede9fe" title="Roxo"></button>
            <button type="button" class="tc-chat-theme-swatch" data-theme="grafite" style="background:#e2e8f0" title="Grafite"></button>
            <button type="button" class="tc-chat-theme-swatch" data-theme="rosa" style="background:#fce7f3" title="Rosa"></button>
            <button type="button" class="tc-chat-theme-swatch" data-theme="esmeralda" style="background:#d1fae5" title="Esmeralda"></button>
            <button type="button" class="tc-chat-theme-swatch" data-theme="laranja" style="background:#ffedd5" title="Laranja"></button>
            <button type="button" class="tc-chat-theme-swatch" data-theme="ardosia" style="background:#cbd5e1" title="Ardósia"></button>
        </div>

        <strong style="font-size:0.78rem;">Papel de parede</strong>
        <div class="btn-group btn-group-sm tc-chat-wallpaper-group w-100 mt-1 mb-2" role="group">
            <button type="button" class="btn btn-outline-secondary" data-wallpaper="none">Nenhum</button>
            <button type="button" class="btn btn-outline-secondary" data-wallpaper="pontos">Pontos</button>
            <button type="button" class="btn btn-outline-secondary" data-wallpaper="linhas">Linhas</button>
        </div>

        <strong style="font-size:0.78rem;">Tipografia</strong>
        <div class="mt-1 mb-2">
            <label class="form-label mb-1" style="font-size:0.72rem;">Tamanho do texto</label>
            <select class="form-select form-select-sm" id="tcChatFontSize">
                <option value="compacto">Compacto</option>
                <option value="padrao">Padrão</option>
                <option value="confortavel">Confortável</option>
            </select>
        </div>
        <div>
            <label class="form-label mb-1" style="font-size:0.72rem;">Estilo da fonte</label>
            <select class="form-select form-select-sm" id="tcChatFontFamily">
                <option value="padrao">Padrão</option>
                <option value="arredondada">Arredondada</option>
            </select>
        </div>
    </div>
</div>
