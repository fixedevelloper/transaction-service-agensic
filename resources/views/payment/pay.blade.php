<!DOCTYPE html>
<html>
<head>
    <title>Paiement</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial;
            text-align: center;
            padding: 40px;
            background: #f5f7fa;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 12px;
        }

        button {
            padding: 15px;
            width: 100%;
            background: green;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
        }
    </style>
</head>
<body>

<div class="card">
    <h2>Paiement commande #{{ $order->order_number }}</h2>

    <p>Montant: <strong>{{ $order->total_amount }} FCFA</strong></p>

    <form method="GET" action="{{ url('/payment/success/'.$order->id) }}">
        <button type="submit">Payer maintenant</button>
    </form>

    <br>

    <form method="GET" action="{{ url('/payment/cancel/'.$order->id) }}">
        <button style="background:red;">Annuler</button>
    </form>
</div>

</body>
</html>
