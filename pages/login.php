<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#13162f">
    <title>Connexion — Keepnew</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <span class="logo-text-lg">Keep<span class="logo-accent">new</span></span>
                <p class="login-subtitle">Suivi des prestations</p>
            </div>

            <form id="loginForm" class="login-form">
                <div class="form-group">
                    <label class="form-label">Nom</label>
                    <input type="text" id="loginName" name="name" class="form-input"
                           placeholder="Votre prénom" autocomplete="username" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Code PIN</label>
                    <div class="pin-input-wrapper">
                        <input type="password" id="loginPin" name="pin" class="form-input pin-input"
                               placeholder="• • • •" maxlength="8" inputmode="numeric"
                               autocomplete="current-password" required>
                        <button type="button" class="pin-toggle" id="pinToggle" aria-label="Afficher PIN">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div id="loginError" class="alert alert-error" style="display:none"></div>

                <button type="submit" class="btn btn-primary btn-full" id="loginBtn">
                    <span>Se connecter</span>
                    <div class="btn-spinner" style="display:none"></div>
                </button>
            </form>

            <p class="login-footer">Keepnew &copy; <?= date('Y') ?></p>
        </div>
    </div>

    <script>
    document.getElementById('loginForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('loginBtn');
        const err = document.getElementById('loginError');
        const spinner = btn.querySelector('.btn-spinner');
        const label = btn.querySelector('span');

        btn.disabled = true;
        spinner.style.display = 'inline-block';
        label.style.opacity = '0';
        err.style.display = 'none';

        try {
            const fd = new FormData(this);
            const res = await fetch('api/login.php', {method:'POST', body: fd});
            const data = await res.json();
            if (data.success) {
                window.location.href = data.redirect;
            } else {
                err.textContent = data.error || 'Erreur de connexion';
                err.style.display = 'block';
                btn.disabled = false;
                spinner.style.display = 'none';
                label.style.opacity = '1';
            }
        } catch(ex) {
            err.textContent = 'Erreur réseau, réessayez.';
            err.style.display = 'block';
            btn.disabled = false;
            spinner.style.display = 'none';
            label.style.opacity = '1';
        }
    });

    document.getElementById('pinToggle').addEventListener('click', function() {
        const inp = document.getElementById('loginPin');
        inp.type = inp.type === 'password' ? 'text' : 'password';
    });
    </script>
</body>
</html>
