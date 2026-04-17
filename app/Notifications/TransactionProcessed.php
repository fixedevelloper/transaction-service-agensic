<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use NotificationChannels\Telegram\TelegramChannel;

class TransactionProcessed extends Notification
{
    use Queueable;

    protected $order;

    // On passe l'objet Order au constructeur
    public function __construct($order)
    {
        $this->order = $order;
    }

    public function via($notifiable)
    {
        return [TelegramChannel::class];
    }

    public function toTelegram($notifiable)
    {
        // Préparation des données pour éviter la répétition dans le content
        $orderId = $this->order->id;
        $amount = number_format($this->order->amount, 2);
        $currency = $this->order->currency ?? 'EUR'; // Utilise la devise dynamique

        // Informations Expéditeur
        $senderName = $this->order->sender->name;
        $senderPhone = $this->order->sender->phone ?? 'N/A';

        // Informations Bénéficiaire
        $beneficiaryName = $this->order->beneficiary->name;
        $beneficiaryPhone = $this->order->beneficiary->phone ?? 'N/A';
        $country = $this->order->beneficiary->country;

        return TelegramMessage::create()
            ->to(config('services.telegram-bot-api.group_id'))
            ->content(
                "🚀 *NOUVELLE TRANSACTION* \n" .
                "───────────────────\n" .
                "🆔 *Référence:* #`{$orderId}`\n\n" .

                "👤 *EXPÉDITEUR :*\n" .
                "└ Nom: {$senderName}\n" .
                "└ Tel: {$senderPhone}\n\n" .

                "🏁 *BÉNÉFICIAIRE :*\n" .
                "└ Nom: {$beneficiaryName}\n" .
                "└ Tel: {$beneficiaryPhone}\n" .
                "└ Pays: {$country}\n\n" .

                "💰 *DÉTAILS FINANCIERS :*\n" .
                "└ Montant: *{$amount} {$currency}*\n" .
                "───────────────────\n" .
                "✅ *Statut:* Traitée avec succès"
            )
            ->button('📂 Voir dans le Panel', env('frontend_url')."/orders/{$orderId}");
    }
}
