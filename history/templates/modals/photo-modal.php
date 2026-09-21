<?php
/* ============================================================================
   PHOTO LIGHTBOX MODAL - Unified wrapper v2.1 - Fixed null checks
   ============================================================================ */
?>
<div x-show="photoLightbox.open" 
     x-cloak
     @click.self="photoLightbox.open = false"
     @keydown.escape.window="photoLightbox.open = false"
     class="edit-route-modal__overlay">

    <div @click.stop class="modal" style="max-width: 90vw;">

        <!-- HEADER -->
        <div class="modal-header">
            <h3 x-text="photoLightbox.caption || 'Zdjęcie'"></h3>
           
            <button @click="photoLightbox.open = false" class="close-btn">
                <img src="<?= asset('icons/x.svg') ?>" alt="" style="width: 18px; height: 18px;">
            </button>
        </div>

        <!-- BODY -->
        <div class="modal-body" style="overflow: hidden;">
            <img :src="photoLightbox.url" 
                 :alt="photoLightbox.caption || 'Zdjęcie'"
                 style="width: 100%; max-height: 70vh; object-fit: contain; border-radius: 8px;">
            
            <!-- EXIF Data -->
            <div x-show="photoLightbox.exif && (photoLightbox.exif.focal_length || photoLightbox.exif.iso || photoLightbox.exif.aperture || photoLightbox.exif.exposure_time)" 
                 style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5e7eb; display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; font-size: 13px; color: #6b7280;">
                
                <span x-show="photoLightbox.exif && photoLightbox.exif.focal_length" 
                      x-text="photoLightbox.exif && photoLightbox.exif.focal_length"></span>
                
                <span x-show="photoLightbox.exif && photoLightbox.exif.aperture" 
                      x-text="photoLightbox.exif && photoLightbox.exif.aperture"></span>
                
                <span x-show="photoLightbox.exif && photoLightbox.exif.iso" 
                      x-text="photoLightbox.exif && photoLightbox.exif.iso ? 'ISO ' + photoLightbox.exif.iso : ''"></span>
                
                <span x-show="photoLightbox.exif && photoLightbox.exif.exposure_time" 
                      x-text="photoLightbox.exif && photoLightbox.exif.exposure_time"></span>
            </div>
        </div>

    </div>
</div>