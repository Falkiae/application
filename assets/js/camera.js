// Camera utilities - included on pages with photo capture
// compressImage is defined in app.js and available globally
// This file provides additional camera-specific utilities if needed

// Auto-rotate images based on EXIF orientation (basic support)
async function fixOrientation(blob) {
    // Modern browsers handle orientation automatically via CSS
    // This is a no-op stub for future enhancement
    return blob;
}

// Validate image file before upload
function validateImageFile(file) {
    if (!file) return 'Aucun fichier sélectionné';
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
    if (!allowedTypes.includes(file.type.toLowerCase())) {
        return 'Format non supporté. Utilisez JPEG, PNG ou WebP.';
    }
    if (file.size > 50 * 1024 * 1024) { // 50MB raw max
        return 'Fichier trop volumineux (max 50 Mo)';
    }
    return null;
}
