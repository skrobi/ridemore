<?php
/* ============================================================================
   PLIK: templates/partials/fab.php
   Floating Action Button with quick actions (MOBILE ONLY)
   ============================================================================ */
?>
<div id="fab-container" style="display: none;" x-init="if (window.innerWidth <= 768) $el.style.display = 'block'">
  <div class="fab-stack">
    <button class="fab-item" :class="{ show: fabOpen }" @click="fabOpen = false; openTracksUpload()" title="Załaduj GPX">
      <i class="fas fa-upload"></i>
    </button>
    <button class="fab-item" :class="{ show: fabOpen }" @click="fabOpen = false; findMe()" title="Znajdź mnie">
      <i class="fas fa-location-crosshairs"></i>
    </button>
    <button class="fab-item" :class="{ show: fabOpen }" @click="fabOpen = false; openMobilePoiCreator()" title="Dodaj miejsce">
      <i class="fas fa-camera"></i>
    </button>
  </div>
  
  <button id="fab-main" :class="{ open: fabOpen }" @click="fabOpen = !fabOpen">
    <i class="fas fa-plus"></i>
  </button>
</div>

<!-- Hidden input for POI photo (mobile) -->
<input type="file" 
       accept="image/*" 
       capture="environment" 
       x-ref="mobilePoiPhotoInput" 
       @change="handleMobilePoiPhoto($event)" 
       style="display: none;">