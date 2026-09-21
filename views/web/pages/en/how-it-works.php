<?php
// views/web/pages/en/how-it-works.php — angielska wersja pages/how-it-works.php
// (View::render wybiera pages/{lang}/… dla języka innego niż domyślny).
// Zmieniając treść polskiej strony, zmień też tę.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<div class="auth-page auth-page-wider">
    <h1 class="display">How it works</h1>
    <p class="subline">Trips and discoveries in one place. Find company, ride the route, and the map will remember your trail.</p>

    <h2 class="section-title">You're going on a trip</h2>

    <div class="day-card">
        <div class="day-num">1</div>
        <div>
            <div class="day-title">Find a trip</div>
            <div class="day-stats" style="font-family:inherit;">Group rides, races, multi-day tours and “ride with someone” — filter by region, distance, difficulty and date, and we'll suggest trips that match your preferences.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">2</div>
        <div>
            <div class="day-title">Sign up</div>
            <div class="day-stats" style="font-family:inherit;">Email, or log in with Google or Strava. It takes a minute and straight away gives you a rider profile with your own map.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">3</div>
        <div>
            <div class="day-title">Join</div>
            <div class="day-stats" style="font-family:inherit;">Sign up for a date, see “who's going” and message the participants. Some organisers handle sign-ups outside the site — then you'll get a link to their sign-ups straight away.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">4</div>
        <div>
            <div class="day-title">Ride</div>
            <div class="day-stats" style="font-family:inherit;">Turn up at the meeting point. After the trip, confirm your attendance and the event's shared chronicle will create itself.</div>
        </div>
    </div>

    <h2 class="section-title">You discover on your own</h2>

    <div class="day-card">
        <div class="day-num">1</div>
        <div>
            <div class="day-title">Upload a ride</div>
            <div class="day-stats" style="font-family:inherit;">A GPX track from your bike computer or app. No track, no ride — the map only counts hexes you've actually ridden.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">2</div>
        <div>
            <div class="day-title">Discover the map</div>
            <div class="day-stats" style="font-family:inherit;">Every hex counts on your map, and we reward new ground with points. You can also take on known routes — you see their full course.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">3</div>
        <div>
            <div class="day-title">Collect treasures</div>
            <div class="day-stats" style="font-family:inherit;">Treasures are hidden along the routes: common, rare and epic. Finders keepers — the find stays in an unchangeable register for ever.</div>
        </div>
    </div>

    <h2 class="section-title">You organise</h2>

    <div class="day-card">
        <div class="day-num">✓</div>
        <div>
            <div class="day-title">Free, no commission</div>
            <div class="day-stats" style="font-family:inherit;">You publish trips free of charge — sign-ups and payments stay on your side.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">✓</div>
        <div>
            <div class="day-title">Four event types</div>
            <div class="day-stats" style="font-family:inherit;">Group rides, races, multi-day tours and “ride with someone” — with multiple dates and your own or external sign-ups.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">✓</div>
        <div>
            <div class="day-title">Trust badge</div>
            <div class="day-stats" style="font-family:inherit;">A verified operator gets a badge — participants know straight away who they're riding with.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">✓</div>
        <div>
            <div class="day-title">Organiser dashboard</div>
            <div class="day-stats" style="font-family:inherit;">The group, sign-ups, payments and questions in one place, and after the trip — a chronicle that creates itself.</div>
        </div>
    </div>

    <p class="spaced-below" style="margin-top:20px;">
        <a class="btn" href="<?= Utils\View::url('/wydarzenia') ?>">Browse trips</a>
        <a class="btn" href="<?= Utils\View::url('/odkrycia') ?>">See the discovery map</a>
        <a class="btn btn-secondary" href="<?= Utils\View::url('/rejestracja') ?>">Sign up</a>
        <a class="btn btn-secondary" href="<?= Utils\View::url('/dla-organizatorow') ?>">For organisers</a>
    </p>
</div>
