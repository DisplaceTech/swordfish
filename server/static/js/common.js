const pepper = new TextEncoder().encode('d783eff0523c8fa7336bc768c5950f63');

let errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
function renderError(error) {
    let message = '<p>' + error + '</p>';

    let errorMessage = document.getElementById('error');
    errorMessage.innerHTML = message;

    errorModal.show();
}

function encodeBuffer(buffer) {
    let array = Array.from(new Uint8Array(buffer));
    return array.map(b => b.toString(16).padStart(2, '0')).join('');
}