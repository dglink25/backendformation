<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $notification->titre }}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 40px 30px;
            text-align: center;
        }
        .header .icon {
            font-size: 48px;
            display: block;
            margin-bottom: 15px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            line-height: 1.3;
        }
        .content {
            padding: 40px 30px;
            color: #333333;
            line-height: 1.6;
        }
        .content p {
            margin: 0 0 20px;
            font-size: 16px;
        }
        .message-box {
            background-color: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 6px 6px 0;
        }
        .message-box p {
            margin: 0;
            font-size: 16px;
            color: #444;
        }
        .button-container {
            text-align: center;
            margin: 30px 0;
        }
        .action-button {
            display: inline-block;
            padding: 15px 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            color: #666666;
            font-size: 14px;
            border-top: 1px solid #e9ecef;
        }
        .footer p { margin: 5px 0; }
        .footer a { color: #667eea; text-decoration: none; }
        .footer .unsubscribe { margin-top: 15px; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="email-container">

        <div class="header">
            <span class="icon">{{ $icone }}</span>
            <h1>{{ $notification->titre }}</h1>
        </div>

        <div class="content">
            <p>Bonjour <strong>{{ $user->name }}</strong>,</p>
            <p>Vous avez reçu une nouvelle notification sur <strong>E-Learning Platform</strong> :</p>

            <div class="message-box">
                <p>{{ $notification->message }}</p>
            </div>

            @if($notification->lien)
                <div class="button-container">
                    <a href="{{ config('app.frontend_url') . $notification->lien }}" class="action-button">
                        Voir les détails
                    </a>
                </div>
            @endif

            <p style="margin-top: 30px;">
                Cordialement,<br>
                <strong>L'équipe E-Learning Platform</strong>
            </p>
        </div>

        <div class="footer">
            <p>© {{ date('Y') }} E-Learning Platform. Tous droits réservés.</p>
            <p>Cet email a été envoyé à <strong>{{ $user->email }}</strong></p>
            <p class="unsubscribe">
                Gérez vos préférences dans
                <a href="{{ config('app.frontend_url') }}/settings">vos paramètres</a>.
            </p>
        </div>

    </div>
</body>
</html>