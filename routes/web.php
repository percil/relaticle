<?php

declare(strict_types=1);

use App\Features\Documentation;
use App\Features\SocialAuth;
use App\Http\Controllers\AcceptTeamInvitationController;
use App\Http\Controllers\Auth\CallbackController;
use App\Http\Controllers\Auth\RedirectController;
use App\Http\Controllers\JoinTeamViaLinkController;
use App\Http\Controllers\SwitchInvitationAccountController;
use App\Http\Middleware\ThrottleBeforeAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use Relaticle\Documentation\Support\DocsRepository;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::middleware('guest')->group(function () {
    if (Feature::active(SocialAuth::class)) {
        Route::get('/auth/redirect/{provider}', RedirectController::class)
            ->name('auth.socialite.redirect')
            ->middleware('throttle:10,1,socialite-redirect');
        Route::get('/auth/callback/{provider}', CallbackController::class)
            ->name('auth.socialite.callback')
            ->middleware('throttle:10,1,socialite-callback');
    }

    Route::get('/login', fn () => redirect()->to(url()->getAppUrl('login')))->name('login');

    Route::get('/register', fn () => redirect()->to(url()->getAppUrl('login')))->name('register');

    Route::get('/forgot-password', fn () => redirect()->to(url()->getAppUrl('forgot-password')))->name('password.request');
});

Route::get('/.well-known/security.txt', function (): Response {
    $lines = [
        'Contact: mailto:'.config('relaticle.contact.email'),
        'Expires: '.now()->addMonths(6)->toIso8601ZuluString(),
        'Preferred-Languages: en',
        'Canonical: '.url('/.well-known/security.txt'),
    ];

    return response(implode("\n", $lines)."\n", Response::HTTP_OK, [
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->name('securityTxt');

// The panel owns "/" once it is domain-routed; registering an app route there
// too would let two route matchers claim the same URI depending on which
// domain resolved first. Guarding on app_panel_domain being unset removes the
// collision by construction instead of relying on registration order.
if (config('app.app_panel_domain') === null) {
    Route::get('/', fn () => redirect()->to(url()->getAppUrl()))->name('home');
}

Route::get('/dashboard', fn () => redirect()->to(url()->getAppUrl()))->name('dashboard');

Route::middleware(['auth', 'verified', 'no-referrer', AuthenticateSession::class])->group(function (): void {
    // Separate buckets: a shared one lets repeated views of the invite page
    // spend the allowance the accept POST needs.
    Route::get('/invitations/{token}', [AcceptTeamInvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{40}')
        ->middleware(ThrottleBeforeAuthentication::class.':10,1,invitation-show')
        ->name('team-invitations.token.accept');

    Route::post('/invitations/{token}', [AcceptTeamInvitationController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{40}')
        ->middleware(ThrottleBeforeAuthentication::class.':10,1,invitation-join')
        ->name('team-invitations.token.join');

    // Signing out returns here rather than to the marketing home, so the invitee
    // lands back on the invitation instead of losing it with the session.
    Route::post('/invitations/{token}/switch-account', SwitchInvitationAccountController::class)
        ->where('token', '[A-Za-z0-9]{40}')
        ->middleware(ThrottleBeforeAuthentication::class.':10,1,invitation-switch')
        ->name('team-invitations.token.switch');
});

Route::middleware(['auth', 'verified', 'no-referrer', AuthenticateSession::class])
    ->group(function (): void {
        Route::get('/join/{token}', [JoinTeamViaLinkController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{40}')
            ->middleware(ThrottleBeforeAuthentication::class.':10,1,team-join-show')
            ->name('teams.join');

        Route::post('/join/{token}', [JoinTeamViaLinkController::class, 'store'])
            ->where('token', '[A-Za-z0-9]{40}')
            ->middleware(ThrottleBeforeAuthentication::class.':10,1,team-join-confirm')
            ->name('teams.join.confirm');
    });

// Legacy documentation redirects. Two indexed generations point here: the
// original /documentation/* URLs and the /docs/* generation retired 2026-08-13
// (renamed to /developers; its two end-user guides moved into /help). Every
// entry maps straight to the final URL so no chain ever exceeds one hop.
$legacyDocsRedirect = function (DocsRepository $repository, string $slug = ''): RedirectResponse {
    $map = [
        'quickstart' => '/help/getting-started',
        'getting-started' => '/help/getting-started',
        'import' => '/help/import',
        'developer' => '/developers/contributing',
    ];

    if (isset($map[$slug])) {
        return redirect($map[$slug], 301);
    }

    $isKnownTarget = $repository->find("docs/guides/{$slug}") !== null
        || "/developers/{$slug}" === config('documentation.api_reference.url');

    return $isKnownTarget
        ? redirect("/developers/{$slug}", 301)
        : redirect('/developers', 301);
};

if (Feature::active(Documentation::class)) {
    Route::get('/documentation/{slug?}', $legacyDocsRedirect)->where('slug', '.*');
    Route::get('/docs/{slug?}', $legacyDocsRedirect)->where('slug', '.*');
}

// Community redirects
Route::get('/discord', function () {
    return redirect()->away(config('services.discord.invite_url'));
})->name('discord');
