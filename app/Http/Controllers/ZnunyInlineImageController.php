<?php

namespace App\Http\Controllers;

use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ZnunyInlineImageContentId;
use App\Services\Znuny\ZnunyInlineImageService;
use App\Services\Znuny\ZnunyTicketCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZnunyInlineImageController extends Controller
{
    public function __construct(
        private readonly ZnunyTicketCacheService $ticketCacheService,
        private readonly ClosedTicketCacheService $closedTicketCacheService,
        private readonly ZnunyInlineImageService $inlineImageService
    ) {}

    public function show(Request $request, string $ticketId, string $articleId, string $token)
    {
        $user = auth()->user();

        if (! $user) {
            return abort(404);
        }

        if (! in_array($user->role ?? '', ['admin', 'operator', 'viewer'], true)) {
            return abort(404);
        }

        if (! preg_match('/^[1-9]\d*$/', $ticketId) || ! preg_match('/^[1-9]\d*$/', $articleId)) {
            return abort(404);
        }

        $ticket = $this->ticketCacheService->getTicket($ticketId);
        if (! $ticket) {
            $ticket = $this->closedTicketCacheService->getTicket($ticketId);
        }

        if (! $ticket) {
            return abort(404);
        }

        try {
            $canonicalContentId = ZnunyInlineImageContentId::decodeToken($token);
        } catch (\Throwable $e) {
            return abort(404);
        }

        try {
            $imageResult = $this->inlineImageService->getInlineImage($ticketId, $articleId, $canonicalContentId);
        } catch (\Throwable $e) {
            Log::error('Znuny inline image fetch failed', [
                'ticket_id' => (int) $ticketId,
                'article_id' => (int) $articleId,
                'exception' => $e::class,
            ]);

            return response('Bad Gateway', 502);
        }

        if (! $imageResult) {
            return abort(404);
        }

        $ttlSeconds = $this->inlineImageService->getCacheTtlSeconds();

        return response($imageResult['content'], 200)
            ->header('Content-Type', $imageResult['content_type'])
            ->header('Content-Disposition', 'inline')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', "private, max-age={$ttlSeconds}");
    }
}
