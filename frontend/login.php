<?php
session_start();
require_once __DIR__ . '/../backend/config/db.php';

// Si déjà connecté, rediriger
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = "Veuillez remplir tous les champs.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Connexion réussie
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_shop_id'] = $user['shop_id'];
            
            // Boutique active par défaut : Tous les dépôts (0) pour l'admin, dépôt assigné pour le vendeur
            if ($user['role'] === 'admin') {
                $_SESSION['active_shop_id'] = 0;
            } else {
                $_SESSION['active_shop_id'] = $user['shop_id'] ?? 1;
            }

            header("Location: index.php");
            exit;
        } else {
            $error = "Identifiants incorrects ou compte inactif.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mandel - Connexion</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/images/icon-192.png">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #fafbfb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-image: radial-gradient(at 0% 0%, rgba(16, 185, 129, 0.04) 0px, transparent 50%),
                              radial-gradient(at 100% 0%, rgba(245, 158, 11, 0.03) 0px, transparent 50%);
        }
        .login-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.02);
            width: 100%;
            max-width: 420px;
            padding: 40px;
        }
        .brand-logo {
            font-size: 2.25rem;
            font-weight: 800;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -1px;
            text-align: center;
            margin-bottom: 5px;
        }
        .btn-grad {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: white;
            border: none;
            font-weight: 600;
            border-radius: 10px;
            padding: 0.75rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px -3px rgba(5, 150, 105, 0.3);
        }
        .btn-grad:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            box-shadow: 0 10px 18px -5px rgba(5, 150, 105, 0.4);
            color: white;
        }
        .form-control-custom {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
            background: #fafbfb;
        }
        .form-control-custom:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.12);
            background: white;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="text-center mb-3">
        <img src="assets/images/logo.png" alt="Mandel" style="height: 64px; max-width: 100%; object-fit: contain;">
    </div>
    <p class="text-center text-secondary small mb-4">Gestion de Stock & Facturation de Papeterie</p>

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 small mb-4" style="border-radius: 10px;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error ?>
        </div>
    <?php endif; ?>

    <form action="login.php" method="POST">
        <div class="mb-3">
            <label for="username" class="form-label small fw-semibold text-secondary">Nom d'utilisateur</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0 text-secondary"><i class="fa-solid fa-user"></i></span>
                <input type="text" class="form-control form-control-custom border-start-0 ps-0" id="username" name="username" placeholder="Ex: admin" required>
            </div>
        </div>

        <div class="mb-4">
            <label for="password" class="form-label small fw-semibold text-secondary">Mot de passe</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0 text-secondary"><i class="fa-solid fa-lock"></i></span>
                <input type="password" class="form-control form-control-custom border-start-0 border-end-0 ps-0" id="password" name="password" placeholder="••••••••" required>
                <button type="button" class="btn btn-outline-secondary border-start-0 bg-light" id="togglePasswordBtn" title="Afficher/Masquer le mot de passe" style="border-radius: 0 10px 10px 0;">
                    <i class="fa-solid fa-eye text-secondary" id="togglePasswordIcon"></i>
                </button>
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-grad w-100">Se connecter <i class="fa-solid fa-arrow-right-to-bracket ms-1"></i></button>
        </div>
    </form>
    
    <div class="text-center mt-4 text-muted small">
        Papeterie Mandel &copy; <?= date('Y') ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('togglePasswordBtn');
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('togglePasswordIcon');

    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function() {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            toggleIcon.classList.toggle('fa-eye', !isPassword);
            toggleIcon.classList.toggle('fa-eye-slash', isPassword);
        });
    }
});
</script>

</body>
</html>

