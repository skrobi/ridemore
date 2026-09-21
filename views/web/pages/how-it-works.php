<?php
// views/web/pages/how-it-works.php
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<div class="auth-page auth-page-wider">
    <h1 class="display">Jak to działa</h1>
    <p class="subline">Wyjazdy i odkrycia w jednym miejscu. Znajdź towarzystwo, przejedź trasę, a mapa zapamięta Twój szlak.</p>

    <h2 class="section-title">Jedziesz na wyjazd</h2>

    <div class="day-card">
        <div class="day-num">1</div>
        <div>
            <div class="day-title">Znajdź wyjazd</div>
            <div class="day-stats" style="font-family:inherit;">Ustawki, wyścigi, wycieczki wielodniowe i „pokręcę z kimś” — filtruj po regionie, dystansie, trudności i dacie, a my podpowiemy Ci wyjazdy dopasowane do Twoich preferencji.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">2</div>
        <div>
            <div class="day-title">Załóż konto</div>
            <div class="day-stats" style="font-family:inherit;">E-mail albo logowanie Google lub Strava. Trwa minutę i od razu daje Ci profil rowerzysty z własną mapą.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">3</div>
        <div>
            <div class="day-title">Dołącz</div>
            <div class="day-stats" style="font-family:inherit;">Zapisz się na termin, zobacz „kto jedzie” i napisz do uczestników. Część organizatorów prowadzi zapisy poza serwisem — wtedy dostaniesz od razu link do ich zapisów.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">4</div>
        <div>
            <div class="day-title">Jedź</div>
            <div class="day-stats" style="font-family:inherit;">Pojaw się na miejscu zbiórki. Po wyjeździe potwierdź obecność, a wspólna kronika wydarzenia powstanie sama.</div>
        </div>
    </div>

    <h2 class="section-title">Odkrywasz na własną rękę</h2>

    <div class="day-card">
        <div class="day-num">1</div>
        <div>
            <div class="day-title">Wgraj przejazd</div>
            <div class="day-stats" style="font-family:inherit;">Ślad z licznika lub aplikacji w formacie GPX. Bez śladu nie ma przejazdu — mapa liczy tylko faktycznie przejechane pola.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">2</div>
        <div>
            <div class="day-title">Odkrywaj mapę</div>
            <div class="day-stats" style="font-family:inherit;">Każde pole zalicza się na Twojej mapie, a nowy teren nagradzamy punktami. Możesz też mierzyć się ze znanymi trasami — widzisz ich pełny przebieg.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">3</div>
        <div>
            <div class="day-title">Zbieraj skarby</div>
            <div class="day-stats" style="font-family:inherit;">Na trasach ukryte są skarby: zwykłe, rzadkie i epickie. Kto znajdzie, ten ma — znalezisko zostaje w niezmiennym rejestrze na zawsze.</div>
        </div>
    </div>

    <h2 class="section-title">Organizujesz</h2>

    <div class="day-card">
        <div class="day-num">✓</div>
        <div>
            <div class="day-title">Za darmo, bez prowizji</div>
            <div class="day-stats" style="font-family:inherit;">Publikujesz wyjazdy bez opłat — zapisy i płatności zostają po Twojej stronie.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">✓</div>
        <div>
            <div class="day-title">Cztery typy wydarzeń</div>
            <div class="day-stats" style="font-family:inherit;">Ustawki, wyścigi, wycieczki wielodniowe i „pokręcę z kimś” — z wieloma terminami oraz zapisami własnymi albo zewnętrznymi.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">✓</div>
        <div>
            <div class="day-title">Odznaka zaufania</div>
            <div class="day-stats" style="font-family:inherit;">Zweryfikowany operator ma odznakę — uczestnik od razu wie, z kim jedzie.</div>
        </div>
    </div>
    <div class="day-card">
        <div class="day-num">✓</div>
        <div>
            <div class="day-title">Panel organizatora</div>
            <div class="day-stats" style="font-family:inherit;">Skład, zapisy, płatności i pytania w jednym miejscu, a po wyjeździe — kronika, która powstaje sama.</div>
        </div>
    </div>

    <p class="spaced-below" style="margin-top:20px;">
        <a class="btn" href="<?= Utils\View::url('/wydarzenia') ?>">Przeglądaj wyjazdy</a>
        <a class="btn" href="<?= Utils\View::url('/odkrycia') ?>">Zobacz mapę odkryć</a>
        <a class="btn btn-secondary" href="<?= Utils\View::url('/rejestracja') ?>">Załóż konto</a>
        <a class="btn btn-secondary" href="<?= Utils\View::url('/dla-organizatorow') ?>">Dla organizatorów</a>
    </p>
</div>
