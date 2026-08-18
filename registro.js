// Registro de usuario para VirtualGaming
// Este script asume que el formulario tiene los siguientes IDs:
// #nombre, #correo, #telefono, #contrasena, #registro-btn

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('registro-form');
    if (!form) return;

    const registerScript = document.querySelector('script[data-register-endpoint]');
    const registerEndpoint = registerScript?.dataset?.registerEndpoint || 'register_user.php';
    const loginUrl = registerScript?.dataset?.loginUrl || 'login.php';

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const nombre = document.getElementById('nombre').value.trim();
        const correo = document.getElementById('correo').value.trim();
        const telefono = document.getElementById('telefono').value.trim();
        const contrasena = document.getElementById('contrasena').value;
        // Sistema de Referidos: código guardado en includes/footer.php cuando el
        // visitante llegó con ?ref=CODIGO, si nunca llegó con uno queda vacío.
        let ref = '';
        try {
            ref = localStorage.getItem('tvg_referido_codigo') || '';
        } catch (e) {}
        const btn = document.getElementById('registro-btn');
        btn.disabled = true;
        btn.textContent = 'Registrando...';
        try {
            const res = await fetch(registerEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ nombre, correo, telefono, contrasena, ref })
            });

            const raw = await res.text();
            let data;

            try {
                data = JSON.parse(raw);
            } catch (parseError) {
                throw new Error(raw || 'Respuesta inválida del servidor.');
            }

            if (data.success) {
                alert('¡Registro exitoso! Ahora puedes iniciar sesión.');
                window.location.href = loginUrl;
            } else {
                alert(data.message || 'Error al registrar.');
            }
        } catch (err) {
            const message = err instanceof Error && err.message ? err.message : 'Error de red o del servidor.';
            alert(message);
        }
        btn.disabled = false;
        btn.textContent = 'REGISTRARSE AHORA';
    });
});
