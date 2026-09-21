<?php
// core/Controllers/ReviewController.php
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Core\Mailer;
use Models\Event;
use Models\EventPhoto;
use Models\EventReview;
use Models\EventRsvp;
use Models\Organizer;
use Models\User;
use Utils\Upload;
use Utils\View;

class ReviewController
{
    public static function form(string $slug): void
    {
        $user = Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        if ($event->statusCode !== 'completed') {
            echo __('Opinie można wystawiać dopiero po zakończeniu wydarzenia.');
            return;
        }
        if (!EventRsvp::isConfirmedParticipant($event->id, $user->id)) {
            http_response_code(403);
            echo __('Tylko potwierdzeni uczestnicy mogą wystawić opinię.');
            return;
        }
        if (EventReview::hasReviewed($event->id, $user->id, $event->organizerId)) {
            echo __('Już wystawiłeś opinię dla tego wydarzenia.');
            return;
        }

        View::render('web', 'review-form', [
            'title'       => __('Opinia: {tytul} — ridemore.bike', ['tytul' => $event->title]),
            'event'       => $event,
            'breadcrumbs' => [Support::homeCrumb(), ['label' => $event->title, 'url' => View::url('/events/' . $slug)], ['label' => __('Opinia')]],
        ]);
    }

    public static function submit(string $slug): void
    {
        $user = Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        if ($event->statusCode !== 'completed' || !EventRsvp::isConfirmedParticipant($event->id, $user->id)
            || EventReview::hasReviewed($event->id, $user->id, $event->organizerId)) {
            http_response_code(403);
            echo __('Nie możesz wystawić opinii dla tego wydarzenia.');
            return;
        }

        $fail = function (string $error) use ($event, $slug) {
            View::render('web', 'review-form', [
                'title'       => __('Opinia: {tytul} — ridemore.bike', ['tytul' => $event->title]),
                'event'       => $event,
                'error'       => $error,
                'breadcrumbs' => [Support::homeCrumb(), ['label' => $event->title, 'url' => View::url('/events/' . $slug)], ['label' => __('Opinia')]],
            ]);
        };

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $fail(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }

        $rating = (int) ($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            $fail(__('Wybierz ocenę od 1 do 5 gwiazdek.'));
            return;
        }
        $comment = trim($_POST['comment'] ?? '') ?: null;

        $reviewId = EventReview::create($event->id, $user->id, $event->organizerId, $rating, $comment);
        if ($reviewId === null) {
            $fail(__('Już wystawiłeś opinię dla tego wydarzenia.'));
            return;
        }

        try {
            $urls = Upload::saveGalleryPhotos($_FILES['photos'] ?? []);
            EventPhoto::attach($event->id, $user->id, $urls, $reviewId, null);
        } catch (\Throwable $e) {
            // Opinia jest już zapisana — brak/błąd zdjęć nie może jej cofnąć, tylko pomijamy zdjęcia.
        }

        $organizerUser = $user->id !== $event->organizerId ? User::find($event->organizerId) : null;
        if ($organizerUser) {
            \Core\Lang::with(\Core\Lang::forEmail((string) (Organizer::notificationEmail($organizerUser))), static fn() => Mailer::sendTemplate('review-posted', Organizer::notificationEmail($organizerUser), __('Nowa opinia: „{tytul}”', ['tytul' => $event->title]), [
                'recipientName' => $organizerUser->displayName(),
                'reviewerName'  => $user->name ?: $user->email,
                'eventTitle'    => $event->title,
                'rating'        => $rating,
                'comment'       => $comment,
                'link'          => View::absoluteUrl('/events/' . $slug) . '#opinie',
            ]));
        }

        header('Location: ' . View::url('/events/' . $slug));
        exit;
    }
}
