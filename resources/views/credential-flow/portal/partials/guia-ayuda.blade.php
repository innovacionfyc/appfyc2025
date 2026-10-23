{{-- Consejos de «¿Necesitas ayuda?» de la guía (se usa en la pantalla final y en la ventana de ayuda). --}}
<div class="ay-grid">
    <div class="ay-card">
        <span class="ay-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 10h4M7 14h6M15 10h2"/></svg></span>
        <div><p class="ay-t">Revisa tu número de documento</p><p class="ay-d">Escríbelo completo y sin errores. Debe ser el mismo número con el que te inscribiste en la capacitación.</p></div>
    </div>
    <div class="ay-card">
        <span class="ay-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span>
        <div><p class="ay-t">Confirma que tu correo esté bien escrito</p><p class="ay-d">Revisa la arroba (@), los puntos y la terminación (por ejemplo, .com). Usa el mismo correo que diste al inscribirte.</p></div>
    </div>
    <div class="ay-card">
        <span class="ay-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13h4l2 3h4l2-3h4"/><path d="M5 5h14l1 8v6H4v-6z"/></svg></span>
        <div><p class="ay-t">Busca en Spam o Correo no deseado</p><p class="ay-d">Si el código no llega a tu bandeja de entrada, revisa esas carpetas y también Promociones. Si ya pasaron {{ (int) config('credential_flow.portal.otp_vigencia_minutos') }} minutos, toca «Enviar otro código».</p></div>
    </div>
    <div class="ay-card">
        <span class="ay-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></span>
        <div><p class="ay-t">Encuentra la capacitación correcta</p><p class="ay-d">Los certificados más recientes salen primero. Si no ves el tuyo, escribe una palabra del nombre en «Buscar capacitación» o elige «Nombre (A–Z)» en «Ordenar».</p></div>
    </div>
    <div class="ay-card ay-contacto" data-guia-contacto>
        <img class="ay-ovi" src="/images/ovi/ovi-telefono.png" alt="" width="181" height="277">
        <div>
            <p class="ay-t">¿Sigue sin funcionar? Escríbenos</p>
            <p class="ay-d">Te ayudamos @if (! empty($contacto))por <a href="{{ $contacto }}" target="_blank" rel="noopener noreferrer">WhatsApp</a> o @endif al correo <a href="mailto:contactenos@fycconsultores.com">contactenos@fycconsultores.com</a>.</p>
        </div>
    </div>
</div>
