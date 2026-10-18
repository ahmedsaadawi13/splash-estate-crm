<?php
// FILE: /app/views/partials/property_gallery.php
/**
 * Property image gallery (embedded in properties/view).
 *
 * Variables:
 * - $property  Property row (required; id is used)
 * - $images    Rows from PropertyImage::getPropertyImages() (optional, default none)
 * - $canEdit   Whether upload/manage controls are shown (default can('properties.images'))
 *
 * Images are served by PropertyGalleryController::image($id) (tenant-checked);
 * management calls go to /propertygallery/* with the CSRF header added by the
 * layout's fetch() wrapper.
 */

$galleryPropertyId = (int)$property['id'];
$galleryImages = array();
foreach ((isset($images) && is_array($images) ? $images : array()) as $img) {
    $galleryImages[] = array(
        'id' => (int)$img['id'],
        'file_name' => (string)$img['file_name'],
        'caption' => isset($img['caption']) ? (string)$img['caption'] : '',
        'is_primary' => !empty($img['is_primary']),
        'url' => BASE_URL . '/propertygallery/image/' . (int)$img['id']
    );
}
$galleryCanEdit = isset($canEdit) ? (bool)$canEdit : can('properties.images');
?>
<div class="card" id="pg-gallery">
    <div class="card-header">
        <h2>Photos <span class="text-muted small" id="pg-count">(<?php echo count($galleryImages); ?>)</span></h2>
    </div>
    <div class="card-body">
        <div id="pg-grid" class="pg-grid">
            <?php if (empty($galleryImages)): ?>
                <p class="text-muted" id="pg-empty">No photos uploaded yet.</p>
            <?php else: ?>
                <?php foreach ($galleryImages as $index => $img): ?>
                <figure class="pg-item">
                    <img src="<?php echo e($img['url']); ?>" alt="<?php echo e($img['caption'] !== '' ? $img['caption'] : $img['file_name']); ?>" data-index="<?php echo (int)$index; ?>" loading="lazy">
                    <?php if ($img['is_primary']): ?><span class="badge badge-warning pg-primary">Primary</span><?php endif; ?>
                    <?php if ($img['caption'] !== ''): ?><figcaption><?php echo e($img['caption']); ?></figcaption><?php endif; ?>
                </figure>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($galleryCanEdit): ?>
        <form id="pg-upload-form" class="mt-2" enctype="multipart/form-data" method="post"
              action="<?php echo e(url('propertygallery/upload/' . $galleryPropertyId)); ?>">
            <?php echo csrf_field(); ?>
            <div class="d-flex gap-1 align-center flex-wrap">
                <input type="file" name="images[]" id="pg-files" class="form-control" style="max-width: 360px;"
                       accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp" multiple>
                <button type="submit" class="btn btn-primary btn-sm" id="pg-upload-btn">Upload photos</button>
            </div>
            <span class="form-help">JPG, PNG, GIF or WebP &middot; up to 10 files, 5 MB each. The first photo becomes the primary image.</span>
            <div id="pg-message" class="mt-1 small" role="status"></div>
        </form>
        <?php endif; ?>
    </div>
</div>

<div id="pg-lightbox" class="pg-lightbox" hidden>
    <button type="button" class="pg-lb-close" aria-label="Close">&times;</button>
    <button type="button" class="pg-lb-nav pg-lb-prev" aria-label="Previous">&#10094;</button>
    <figure>
        <img id="pg-lb-img" src="" alt="">
        <figcaption id="pg-lb-caption"></figcaption>
    </figure>
    <button type="button" class="pg-lb-nav pg-lb-next" aria-label="Next">&#10095;</button>
</div>

<style>
.pg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 0.75rem; }
.pg-item { position: relative; margin: 0; background: #ecf0f1; border-radius: 6px; overflow: hidden; }
.pg-item img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; cursor: zoom-in; }
.pg-item figcaption { font-size: 12px; padding: 0.3rem 0.5rem; color: #2c3e50; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pg-primary { position: absolute; top: 6px; left: 6px; }
.pg-actions { display: flex; gap: 0.25rem; padding: 0.3rem 0.4rem 0.45rem; flex-wrap: wrap; }
.pg-actions .btn { padding: 0.15rem 0.45rem; font-size: 11px; }
.pg-lightbox { position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,0.9); display: flex; align-items: center; justify-content: center; }
.pg-lightbox[hidden] { display: none; }
.pg-lightbox figure { margin: 0; max-width: 85vw; text-align: center; }
.pg-lightbox img { max-width: 85vw; max-height: 80vh; object-fit: contain; }
.pg-lightbox figcaption { color: #fff; margin-top: 0.5rem; }
.pg-lb-close, .pg-lb-nav { background: rgba(255,255,255,0.15); color: #fff; border: 0; border-radius: 4px; font-size: 28px; cursor: pointer; padding: 0.25rem 0.8rem; }
.pg-lb-close { position: absolute; top: 1rem; right: 1rem; }
.pg-lb-nav { margin: 0 1rem; }
</style>

<script>
(function () {
    var propertyId = <?php echo (int)$galleryPropertyId; ?>;
    var canEdit = <?php echo $galleryCanEdit ? 'true' : 'false'; ?>;
    var images = <?php echo json_encode($galleryImages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var base = window.BASE_URL || '';
    var grid = document.getElementById('pg-grid');
    var countEl = document.getElementById('pg-count');
    var message = document.getElementById('pg-message');
    var lightbox = document.getElementById('pg-lightbox');
    var lbImg = document.getElementById('pg-lb-img');
    var lbCaption = document.getElementById('pg-lb-caption');
    var current = 0;

    function setMessage(text, isError) {
        if (!message) { if (isError) { alert(text); } return; }
        message.textContent = text;
        message.className = 'mt-1 small ' + (isError ? 'text-danger' : 'text-success');
    }

    function api(path, options) {
        options = options || {};
        options.headers = options.headers || {};
        options.headers['Accept'] = 'application/json';
        options.headers['X-Requested-With'] = 'XMLHttpRequest';
        options.credentials = 'same-origin';
        return fetch(base + '/propertygallery/' + path, options).then(function (res) {
            return res.json().catch(function () { return { success: false, error: 'Unexpected server response (' + res.status + ')' }; });
        });
    }

    function postJson(path, payload) {
        return api(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    }

    function button(label, cls, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + cls;
        b.textContent = label;
        b.addEventListener('click', onClick);
        return b;
    }

    function render() {
        grid.innerHTML = '';
        countEl.textContent = '(' + images.length + ')';
        if (!images.length) {
            var p = document.createElement('p');
            p.className = 'text-muted';
            p.textContent = 'No photos uploaded yet.';
            grid.appendChild(p);
            return;
        }
        images.forEach(function (img, index) {
            var fig = document.createElement('figure');
            fig.className = 'pg-item';

            var el = document.createElement('img');
            el.src = img.url;
            el.alt = img.caption || img.file_name;
            el.loading = 'lazy';
            el.addEventListener('click', function () { open(index); });
            fig.appendChild(el);

            if (img.is_primary) {
                var badge = document.createElement('span');
                badge.className = 'badge badge-warning pg-primary';
                badge.textContent = 'Primary';
                fig.appendChild(badge);
            }
            if (img.caption) {
                var cap = document.createElement('figcaption');
                cap.textContent = img.caption;
                fig.appendChild(cap);
            }
            if (canEdit) {
                var actions = document.createElement('div');
                actions.className = 'pg-actions';
                if (!img.is_primary) {
                    actions.appendChild(button('Make primary', 'btn-outline', function () { setPrimary(img.id); }));
                }
                actions.appendChild(button('Caption', 'btn-outline', function () { editCaption(img); }));
                actions.appendChild(button('Delete', 'btn-danger', function () { removeImage(img.id); }));
                fig.appendChild(actions);
            }
            grid.appendChild(fig);
        });
    }

    function reload() {
        return api('getImages/' + propertyId).then(function (data) {
            if (data.success) { images = data.images || []; render(); }
        });
    }

    function setPrimary(id) {
        postJson('setPrimary', { image_id: id, property_id: propertyId }).then(function (data) {
            if (data.success) { reload(); setMessage('Primary photo updated.', false); }
            else { setMessage(data.error || data.message || 'Failed to set primary photo', true); }
        });
    }

    function editCaption(img) {
        var caption = window.prompt('Caption for this photo', img.caption || '');
        if (caption === null) { return; }
        postJson('updateCaption', { image_id: img.id, caption: caption }).then(function (data) {
            if (data.success) { reload(); }
            else { setMessage(data.error || data.message || 'Failed to save caption', true); }
        });
    }

    function removeImage(id) {
        if (!window.confirm('Delete this photo?')) { return; }
        postJson('deleteImage/' + id, {}).then(function (data) {
            if (data.success) { reload(); setMessage('Photo deleted.', false); }
            else { setMessage(data.error || data.message || 'Failed to delete photo', true); }
        });
    }

    function open(index) {
        if (!images.length) { return; }
        current = (index + images.length) % images.length;
        lbImg.src = images[current].url;
        lbImg.alt = images[current].caption || images[current].file_name;
        lbCaption.textContent = images[current].caption || images[current].file_name;
        lightbox.hidden = false;
    }

    function close() { lightbox.hidden = true; lbImg.src = ''; }

    lightbox.querySelector('.pg-lb-close').addEventListener('click', close);
    lightbox.querySelector('.pg-lb-prev').addEventListener('click', function (e) { e.stopPropagation(); open(current - 1); });
    lightbox.querySelector('.pg-lb-next').addEventListener('click', function (e) { e.stopPropagation(); open(current + 1); });
    lightbox.addEventListener('click', function (e) { if (e.target === lightbox) { close(); } });
    document.addEventListener('keydown', function (e) {
        if (lightbox.hidden) { return; }
        if (e.key === 'Escape') { close(); }
        else if (e.key === 'ArrowLeft') { open(current - 1); }
        else if (e.key === 'ArrowRight') { open(current + 1); }
    });

    var form = document.getElementById('pg-upload-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = document.getElementById('pg-files');
            var btn = document.getElementById('pg-upload-btn');
            if (!input.files.length) { setMessage('Choose one or more images first.', true); return; }
            if (input.files.length > 10) { setMessage('You can upload at most 10 images at a time.', true); return; }
            for (var i = 0; i < input.files.length; i++) {
                if (input.files[i].size > 5 * 1024 * 1024) { setMessage(input.files[i].name + ' is larger than 5 MB.', true); return; }
            }
            btn.disabled = true;
            setMessage('Uploading...', false);
            api('upload/' + propertyId, { method: 'POST', body: new FormData(form) }).then(function (data) {
                var text = data.uploaded ? ('Uploaded ' + data.uploaded + ' photo(s).') : '';
                if (data.errors && data.errors.length) { text += (text ? ' ' : '') + 'Failed: ' + data.errors.join('; '); }
                if (!text) { text = data.error || data.message || 'Upload failed'; }
                setMessage(text, !data.uploaded);
                form.reset();
                return reload();
            }).catch(function () {
                setMessage('Upload failed. Please try again.', true);
            }).then(function () { btn.disabled = false; });
        });
    }

    render();
})();
</script>
