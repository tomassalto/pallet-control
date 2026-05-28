<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class TelegramBotClient
{
    public function sendMessage(int|string $chatId, string $text, ?array $inlineKeyboard = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        Http::post($this->apiUrl('sendMessage'), $payload);
    }

    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?array $inlineKeyboard = null,
    ): void {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        Http::post($this->apiUrl('editMessageText'), $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        Http::post($this->apiUrl('answerCallbackQuery'), [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]);
    }

    public function downloadPhoto(string $fileId): ?UploadedFile
    {
        $meta = Http::get($this->apiUrl('getFile'), [
            'file_id' => $fileId,
        ]);

        if (!$meta->ok()) {
            return null;
        }

        $filePath = $meta->json('result.file_path');
        if (!$filePath) {
            return null;
        }

        $content = Http::get($this->fileUrl($filePath))->body();
        $tempPath = tempnam(sys_get_temp_dir(), 'tg_') . '.jpg';
        file_put_contents($tempPath, $content);

        return new UploadedFile($tempPath, 'photo.jpg', 'image/jpeg', null, true);
    }

    private function apiUrl(string $method): string
    {
        return 'https://api.telegram.org/bot' . config('services.telegram.token') . "/{$method}";
    }

    private function fileUrl(string $filePath): string
    {
        return 'https://api.telegram.org/file/bot' . config('services.telegram.token') . "/{$filePath}";
    }
}
