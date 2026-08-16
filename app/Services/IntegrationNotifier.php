<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Posts panel alerts to configured outbound channels (Slack, Discord,
 * Telegram, generic webhook). Fail-soft: a channel error never throws to the
 * caller. Configured under Settings > Integrations.
 */
class IntegrationNotifier
{
    public const CHANNELS = ['slack', 'discord', 'telegram', 'webhook'];

    /** Send a message to every enabled channel. Returns the number delivered. */
    public static function notify(string $title, string $body = ''): int
    {
        $sent = 0;
        foreach (self::CHANNELS as $ch) {
            if (Setting::get("integrations_{$ch}_enabled") === '1' && self::send($ch, $title, $body)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Send to one channel regardless of its enabled flag (used by the test button). */
    public static function send(string $channel, string $title, string $body = ''): bool
    {
        try {
            return match ($channel) {
                'slack' => self::deliver('slack', Setting::get('integrations_slack_url'), $title, $body),
                'discord' => self::deliver('discord', Setting::get('integrations_discord_url'), $title, $body),
                'webhook' => self::deliver('webhook', Setting::get('integrations_webhook_url'), $title, $body),
                'telegram' => self::telegram(trim($title."\n".$body)),
                default => false,
            };
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Send to an explicit URL rather than the one in Settings. Alert contacts
     * each carry their own destination, so incident delivery routes through
     * here while the Settings-configured channels still go through send().
     *
     * @param  array<string, mixed>  $extra  merged into the generic webhook body
     */
    public static function deliver(string $channel, ?string $url, string $title, string $body = '', array $extra = []): bool
    {
        if (! $url) {
            return false;
        }

        $text = trim($title."\n".$body);
        try {
            return match ($channel) {
                'slack' => self::post($url, ['text' => $text]),
                'discord' => self::post($url, ['content' => $text]),
                'webhook' => self::post($url, array_merge([
                    'title' => $title, 'body' => $body, 'text' => $text, 'product' => config('brand.name'),
                ], $extra)),
                default => false,
            };
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function post(?string $url, array $payload): bool
    {
        if (! $url) {
            return false;
        }

        return Http::timeout(8)->asJson()->post($url, $payload)->successful();
    }

    private static function telegram(string $text): bool
    {
        $token = Setting::get('integrations_telegram_token');
        $chat = Setting::get('integrations_telegram_chat_id');
        if (! $token || ! $chat) {
            return false;
        }

        return Http::timeout(8)->asJson()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chat, 'text' => $text])
            ->successful();
    }
}
