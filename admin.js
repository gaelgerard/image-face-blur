jQuery(function ($) {
    let currentId = null;
    let imgNaturalWidth = 0;
    let imgNaturalHeight = 0;

    // Detect if we are in list mode or grid mode (handled slightly via delegation)
    // Add Modal HTML to body
    $('body').append(`
        <div class="ifb-modal-overlay" id="ifb-modal">
            <div class="ifb-modal-content">
                <div class="ifb-modal-header">
                    <h2>Sélectionner les zones à flouter</h2>
                    <div class="ifb-modal-actions">
                         <span class="spinner" id="ifb-spinner" style="float:none; visibility:hidden; margin:0 10px;"></span>
                        <button class="button button-secondary" id="ifb-cancel">Annuler</button>
                        <button class="button button-primary" id="ifb-save">Appliquer le flou et Écraser</button>
                    </div>
                </div>
                <div class="ifb-canvas-container" id="ifb-canvas">
                    <div class="ifb-image-wrapper" id="ifb-wrapper">
                        <img id="ifb-target-img" src="" />
                    </div>
                </div>
                <p class="description" style="margin-top: 10px;">Cliquez sur l'image pour ajouter un cercle. Déplacez et redimensionnez les cercles pour couvrir les visages.</p>
            </div>
        </div>
    `);

    // Open Modal
    $(document).on('click', '.ifb-blur-btn', function (e) {
        e.preventDefault();
        currentId = $(this).data('id');
        const url = $(this).data('url');

        $('#ifb-target-img').attr('src', url);

        // Remove old circles
        $('.ifb-blur-circle').remove();

        const img = new Image();
        img.onload = function () {
            imgNaturalWidth = this.naturalWidth;
            imgNaturalHeight = this.naturalHeight;
            $('#ifb-modal').fadeIn();

            // Adjust wrapper size to match image
            // This ensures the wrapper is exactly the size of the image, 
            // so clicks and positions are consistent.
            $('#ifb-wrapper').css({
                width: this.width || 'auto',
                height: this.height || 'auto',
                display: 'inline-block',
                position: 'relative'
            });
        };
        img.src = url;
    });

    // Close Modal
    $('#ifb-cancel').on('click', function () {
        $('#ifb-modal').fadeOut();
    });

    // Add Circle on click (Listener on wrapper to capture full area)
    $('#ifb-wrapper').on('click', function (e) {
        // Prevent adding a circle if we clicked ON an existing circle (bubbling check)
        if ($(e.target).hasClass('ifb-blur-circle') || $(e.target).hasClass('ui-resizable-handle')) {
            return;
        }

        const offset = $(this).offset();
        const displayX = e.pageX - offset.left;
        const displayY = e.pageY - offset.top;

        // Default radius in display pixels
        const radius = 30;

        // Ensure we are inside bounds (optional, but good UX)
        // ...

        const circle = $('<div class="ifb-blur-circle"></div>');

        // Center the circle on click
        circle.css({
            top: (displayY - radius) + 'px',
            left: (displayX - radius) + 'px',
            width: (radius * 2) + 'px',
            height: (radius * 2) + 'px',
            position: 'absolute' // Force explicit position
        });

        $('#ifb-wrapper').append(circle);

        circle.draggable({
            containment: "parent"
        }).resizable({
            aspectRatio: 1, // Keep circle shape
            handles: "n, e, s, w, ne, se, sw, nw", // Add all handles for better UX
            containment: "parent"
        });
    });

    // Save
    $('#ifb-save').on('click', function () {
        if (!confirm("Attention : L'image originale sera écrasée définitivement. Continuer ?")) {
            return;
        }

        const circlesData = [];
        const $img = $('#ifb-target-img');
        const displayWidth = $img.width();
        const displayHeight = $img.height();

        // Recalculate natural width just in case (sometimes img load is weird)
        // Or trust the previous vars.

        const scaleX = imgNaturalWidth / displayWidth;
        const scaleY = imgNaturalHeight / displayHeight;

        $('.ifb-blur-circle').each(function () {
            const pos = $(this).position();
            const w = $(this).outerWidth();
            const h = $(this).outerHeight();

            // Calculate center in display coords
            const cx_display = pos.left + (w / 2);
            const cy_display = pos.top + (h / 2);
            const radius_display = w / 2;

            // Convert to natural coords
            circlesData.push({
                x: Math.round(cx_display * scaleX),
                y: Math.round(cy_display * scaleY),
                r: Math.round(radius_display * Math.max(scaleX, scaleY))
            });
        });

        if (circlesData.length === 0) {
            alert("Aucune zone de flou sélectionnée.");
            return;
        }

        $('#ifb-spinner').css('visibility', 'visible');
        $('#ifb-save').prop('disabled', true);

        $.post(IFB.ajax_url, {
            action: 'ifb_blur_image',
            id: currentId,
            circles: circlesData,
            _ajax_nonce: IFB.nonce
        }, function (response) {
            $('#ifb-spinner').css('visibility', 'hidden');
            $('#ifb-save').prop('disabled', false);

            if (response.success) {
                location.reload();
            } else {
                alert('Erreur : ' + (response.data || 'Inconnue'));
            }
        });
    });
});

