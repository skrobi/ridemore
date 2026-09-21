<!-- Panel mapy — realny Leaflet + geokodowanie Nominatim (nie dekoracyjny SVG
     z makiety), reużywa 1:1 openMap()/setPin()/searchGeocode() z Alpine. -->
<div class="k-mdl" x-show="mapOpen" x-cloak @click.self="mapOpen=false">
    <div class="k-mdl__b" @click.outside="mapOpen=false">
        <div class="k-mdl__h"><span><?= __('Dotknij mapy, żeby ustawić punkt') ?></span>
            <button type="button" class="link-button" style="margin-left:auto;" @click="mapOpen=false"><?= __('Gotowe') ?></button>
        </div>
        <div class="k-mdl__s organizer-search" @click.outside="geocodeResults=[]">
            <input type="text" x-model="geocodeQuery" @input.debounce.500ms="searchGeocode()" placeholder="<?= htmlspecialchars(__('Szukaj miejscowości…')) ?>">
            <div class="organizer-search-results" x-show="geocodeResults.length">
                <template x-for="r in geocodeResults" :key="r.placeId">
                    <div class="organizer-search-result" @click="selectGeocodeResult(r)" x-text="r.label"></div>
                </template>
            </div>
        </div>
        <div class="k-mdl__m" id="picker-map"></div>
        <div class="k-mdl__f"><button type="button" class="btn" style="width:100%;justify-content:center;" @click="mapOpen=false"><?= __('Gotowe') ?></button></div>
    </div>
</div>
