<?php
// core/Utils/Icon.php
namespace Utils;

class Icon
{
    // Małe, jednokolorowe ikony inline (fill/stroke: currentColor — dziedziczą
    // kolor tekstu). Jedyne miejsce z definicjami SVG w aplikacji — zamiast
    // emoji (📍 ✓ ★), które renderują się różnie zależnie od systemu/przeglądarki.
    private const ICONS = [
        'pin' => '<svg class="icon icon-pin" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>',
        'check' => '<svg class="icon icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>',
        'star' => '<svg class="icon icon-star" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
        'close' => '<svg class="icon icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'menu' => '<svg class="icon icon-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
        'camera' => '<svg class="icon icon-camera" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-2h6l2 2h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="14" r="3.5"/></svg>',
        'upload' => '<svg class="icon icon-upload" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 17v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>',
        'grid' => '<svg class="icon icon-grid" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>',
        'phone' => '<svg class="icon icon-phone" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1.1 1A16 16 0 0 1 4 5.1 1 1 0 0 1 5 4z"/></svg>',
        'mail' => '<svg class="icon icon-mail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 6.5 8 6 8-6"/></svg>',
        'link' => '<svg class="icon icon-link" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.7 3.8 9S14.5 18.4 12 21c-2.5-2.6-3.8-5.7-3.8-9S9.5 5.6 12 3z"/></svg>',
        'facebook' => '<svg class="icon icon-facebook" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 8.5V7c0-.8.5-1.2 1.3-1.2H17V3h-2.4C12.2 3 11 4.4 11 6.7v1.8H9V11h2v10h3V11h2.2l.4-2.5z"/></svg>',
        'instagram' => '<svg class="icon icon-instagram" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17" cy="7" r="1.1" fill="currentColor" stroke="none"/></svg>',
        'strava' => '<svg class="icon icon-strava" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m7 14 4-9 4 9h-2.6L11 10.6 9.6 14z"/><path d="m13 14 2.2 5 2.2-5H15.9l-.7 1.6-.7-1.6z"/></svg>',
        'eye' => '<svg class="icon icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z"/><circle cx="12" cy="12" r="3"/></svg>',
        // Dopisane 2026-08-09 pod przebudowę panelu "Moje wydarzenia"
        // (dashboard.php): akcje w wierszu przeszły z linków tekstowych na
        // ikony, a tych sześciu w zestawie brakowało. Ten sam styl co reszta:
        // 24x24, currentColor, stroke-width 2 dla konturowych.
        // POLE SIATKI ODKRYĆ — jeden znak dla wszystkich liczb o odkrywaniu
        // (decyzja usera 2026-08-14). Do tej pory pola były wszędzie samym
        // słowem („373 pola", „nowe pola"), przez co nie dawało się ich odróżnić
        // wzrokiem od kilometrów, punktów i miejsc w składzie.
        //
        // Heksagon SPICZASTY U GÓRY — dokładnie taki, jak pola rysowane na mapie
        // (Utils\DiscoveryGrid, „pointy-top"). Znak ma być tym samym kształtem,
        // który człowiek widzi na mapie, a nie ogólną sześciokątną ozdobą.
        //
        // Kontur, nie wypełnienie: dziedziczy currentColor, więc jest jasny na
        // ciemnym pasku zaufania i ciemny na jasnej karcie. Wypełniony byłby
        // niewidoczny w jednym z tych dwóch miejsc.
        'hex' => '<svg class="icon icon-hex" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.6 20.4 7.3v9.4L12 21.4 3.6 16.7V7.3z"/></svg>',
        // Trasa GPX dostępna — znacznik na karcie wydarzenia (2026-08-13),
        // ten sam znak co wpis „Wgrany ślad" w aktywności rowerzysty.
        //
        // KSZTALT WYMIENIONY 2026-08-15 na prośbę usera. Wcześniej była tu
        // chorągiewka na słupku — dobrana tak, żeby nie mylić się z pinezką
        // (icon-pin znaczy w tym serwisie MIEJSCE, a tu chodzi o ŚLAD). Problem
        // w tym, że chorągiewka nie znaczyła nic: na 15 px czytała się jak
        // „start" albo jak flaga mety, czyli znowu jak punkt, a nie jak droga
        // między dwoma punktami.
        //
        // Teraz Lucide „route" (ISC): dwa końce połączone linią. Mówi wprost
        // „stąd dotąd", nie miesza się z pinezką, bo ma DWA znaczniki zamiast
        // jednego, i zostaje czytelna po zmniejszeniu — trzy elementy.
        'route' => '<svg class="icon icon-route" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg>',
        'edit' => '<svg class="icon icon-edit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
        'users' => '<svg class="icon icon-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'more' => '<svg class="icon icon-more" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>',
        'warning' => '<svg class="icon icon-warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'coins' => '<svg class="icon icon-coins" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>',
        // IKONY KATEGORII SKARBOW (SKA/7, poprawka 2026-08-15).
        //
        // Wczesniej kategorie mialy w bazie EMOJI i to bylo zle z dwoch powodow.
        // Pierwszy widac bylo na ekranie: emoji przeszlo przez konsole Windows
        // w cp852 i do bazy trafily „krzaczki" (0xC2AD 0xC48D... zamiast jednego
        // znaku) — patrz migracja 057. Drugi jest wazniejszy i stoi na gorze
        // tego pliku od poczatku: emoji renderuje sie inaczej na kazdym systemie
        // i nie da sie go pokolorowac razem z tekstem.
        //
        // Ksztalty pochodza z Lucide (licencja ISC, lucide.dev) — ten sam
        // rozmiar i grubosc kreski, co reszta zestawu, wiec wpasowuja sie bez
        // przerabiania. Sciagniete raz i wklejone TUTAJ, a nie ladowane z CDN:
        // caly ten plik istnieje po to, zeby ikona byla jednym zapytaniem mniej.
        //
        // W bazie (dictionary_items.icon) siedzi teraz KLUCZ, nie znak.
        'tre-viewpoint' => '<svg class="icon icon-tre-viewpoint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 3 4 8 5-5 5 15H2L8 3z"/> <path d="M4.14 15.08c2.62-1.57 5.24-1.43 7.86.42 2.74 1.94 5.49 2 8.23.19"/></svg>',
        'tre-pass' => '<svg class="icon icon-tre-pass" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 3 4 8 5-5 5 15H2L8 3z"/></svg>',
        'tre-hut' => '<svg class="icon icon-tre-hut" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="4" cy="4" r="2"/> <path d="m14 5 3-3 3 3"/> <path d="m14 10 3-3 3 3"/> <path d="M17 14V2"/> <path d="M17 14H7l-5 8h20Z"/> <path d="M8 14v8"/> <path d="m9 14 5 8"/></svg>',
        'tre-water' => '<svg class="icon icon-tre-water" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"/> <path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97"/></svg>',
        'tre-monument' => '<svg class="icon icon-tre-monument" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 18v-7"/> <path d="M11.119 2.205a2 2 0 0 1 1.762 0l7.84 3.846A.5.5 0 0 1 20.5 7h-17a.5.5 0 0 1-.22-.949z"/> <path d="M14 18v-7"/> <path d="M18 18v-7"/> <path d="M3 22h18"/> <path d="M6 18v-7"/></svg>',
        'tre-service' => '<svg class="icon icon-tre-service" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.106-3.105c.32-.322.863-.22.983.218a6 6 0 0 1-8.259 7.057l-7.91 7.91a1 1 0 0 1-2.999-3l7.91-7.91a6 6 0 0 1 7.057-8.259c.438.12.54.662.219.984z"/></svg>',
        'tre-curiosity' => '<svg class="icon icon-tre-curiosity" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/> <path d="M20 2v4"/> <path d="M22 4h-4"/> <circle cx="4" cy="20" r="2"/></svg>',
        // --- PASEK DOLNY APKI (zgłoszenie usera 2026-08-29: „ikony w aplikacji
        // w footer są różne i podpisy też") ---
        //
        // Pasek mieszał TRZY systemy naraz: ikony konturowe (`calendar`,
        // `user`), ikonę WYPEŁNIONĄ (`pin`) oraz emoji 🗺️ i znak typograficzny
        // ⌗ — a te dwa ostatnie rysuje font systemu, więc na każdym telefonie
        // wyglądały inaczej i nie dawało się ich ani dopasować grubością, ani
        // pokolorować. Te trzy pochodzą z Lucide (licencja ISC,
        // github.com/lucide-icons/lucide), tak samo jak `route` wyżej,
        // i trzymają jeden zestaw parametrów: 24×24, bez wypełnienia,
        // stroke-width 2, zaokrąglone końce.
        'map' => '<svg class="icon icon-map" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z"/> <path d="M15 5.764v15"/> <path d="M9 3.236v15"/></svg>',
        'scan' => '<svg class="icon icon-scan" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2"/> <path d="M17 3h2a2 2 0 0 1 2 2v2"/> <path d="M21 17v2a2 2 0 0 1-2 2h-2"/> <path d="M7 21H5a2 2 0 0 1-2-2v-2"/> <path d="M7 12h10"/></svg>',
        // Pinezka Z PLUSEM, nie zwykła: ten slot ZGŁASZA nowy punkt, a nie
        // pokazuje istniejący. `pin` (wypełniony) zostaje tam, gdzie znaczy
        // MIEJSCE — nie podmieniamy go, żeby nie ruszać reszty serwisu.
        'pin-add' => '<svg class="icon icon-pin-add" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19.914 11.105A7.298 7.298 0 0 0 20 10a8 8 0 0 0-16 0c0 4.993 5.539 10.193 7.399 11.799a1 1 0 0 0 1.202 0 32 32 0 0 0 .824-.738"/> <circle cx="12" cy="10" r="3"/> <path d="M16 18h6"/> <path d="M19 15v6"/></svg>',
        'calendar' => '<svg class="icon icon-calendar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>',
        // Kalendarz Z PLUSEM (2026-09-14) — ikona „Dodaj wydarzenie" w nagłówku;
        // zwykły `calendar` znaczy termin, nie akcję.
        'calendar-add' => '<svg class="icon icon-calendar-add" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7"/><path d="M16 3v4M8 3v4M3 11h18M19 16v6M16 19h6"/></svg>',
        // Dopisane pod pasek nawigacji apki (2026-08-28, §13 audytu UX) — slot
        // „Profil" renderował raw 👤, jedyne miejsce w pasku, gdzie nie było
        // gotowego klucza. `users` (wyżej) to DWIE sylwetki — właściwe dla
        // składu/grupy, nie dla „to jesteś Ty". Ta sama sylwetka co `users`,
        // bez drugiej postaci, więc kroje pozostają rodziną.
        'user' => '<svg class="icon icon-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        // Dopisane 2026-09-13 pod profil rowerzysty: kłódka = „widzisz tylko Ty"
        // (skróty i liczby właściciela), znak zapytania = skarb-tajemnica,
        // której oglądający jeszcze nie rozwiązał.
        'lock' => '<svg class="icon icon-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
        'mystery' => '<svg class="icon icon-mystery" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.2 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4.5"/><circle cx="12" cy="18.5" r=".6" fill="currentColor"/><path d="M12 2 20.7 7v10L12 22 3.3 17V7z"/></svg>',
        // --- ROUTE PLANNER, ETAP 1 (2026-09-17) — sześć nowych, panel
        // konfiguracji trasy. Ta sama rodzina Lucide (ISC) co reszta zestawu;
        // 'wzniesienie' celowo NIE dostaje nowej ikony — 'tre-pass' (trójkąt
        // góry) już istnieje i znaczy dokładnie to samo, dystans/czas na
        // odcinku reużywa 'route'/nowego 'clock'.
        'settings' => '<svg class="icon icon-settings" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 0 1-4 0v-.09A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 0 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 0 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.55 1H21a2 2 0 0 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1Z"/></svg>',
        'bike' => '<svg class="icon icon-bike" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18.5" cy="17.5" r="3.5"/><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="M12 17.5V14l-3-3 4-3 2 3h2"/></svg>',
        'layers' => '<svg class="icon icon-layers" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></svg>',
        'magnet' => '<svg class="icon icon-magnet" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 15-4-4 6.75-6.77a7.79 7.79 0 0 1 11 11L13 22l-4-4 6.39-6.36a2.14 2.14 0 0 0-3-3L6 15"/><path d="m5 8 4 4"/><path d="m12 15 4 4"/></svg>',
        'download' => '<svg class="icon icon-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'grip' => '<svg class="icon icon-grip" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="5" r="1.3"/><circle cx="9" cy="12" r="1.3"/><circle cx="9" cy="19" r="1.3"/><circle cx="15" cy="5" r="1.3"/><circle cx="15" cy="12" r="1.3"/><circle cx="15" cy="19" r="1.3"/></svg>',
        'trash' => '<svg class="icon icon-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>',
        'save' => '<svg class="icon icon-save" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg>',
        'clock' => '<svg class="icon icon-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    ];

    public static function render(string $name): string
    {
        return self::ICONS[$name] ?? throw new \RuntimeException("Nieznana ikona: $name");
    }

    /**
     * Ikona, ktorej nazwa przychodzi Z BAZY — i dlatego nie rzuca wyjatkiem.
     *
     * render() ma racje, ze wybucha: nazwa wpisana w kodzie albo istnieje, albo
     * jest literowka do poprawienia. Ale nazwa ze slownika moze byc pusta,
     * moze pochodzic ze starszego wpisu albo zostac skasowana przez admina —
     * i zaden z tych przypadkow nie jest powodem, zeby przewrocic cala strone.
     * Brak ikony po prostu znika, zostaje sam tekst.
     */
    public static function maybe(?string $name): string
    {
        if ($name === null || $name === '') {
            return '';
        }
        return self::ICONS[$name] ?? '';
    }
}
