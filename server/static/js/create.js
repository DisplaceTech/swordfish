let submitBtn = document.getElementById('encrypt');
let passphraseFld = document.getElementById('passphrase');
let secretFld = document.getElementById('secret');

let successModal = new bootstrap.Modal(document.getElementById('successModal'));

async function encrypt() {
    let encoder = new TextEncoder();

    let plaintext = encoder.encode(secretFld.value);
    let passphrase = encoder.encode(passphraseFld.value);
    let salt = await window.crypto.getRandomValues(new Uint8Array(16));

    let passkey = await window.crypto.subtle.importKey(
        'raw',
        passphrase,
        {'name': 'PBKDF2'},
        false,
        ['deriveBits', 'deriveKey']
    );

    let verifier = await window.crypto.subtle.deriveBits(
        {
            'name': 'PBKDF2',
            'salt': pepper,
            'iterations': 10000,
            'hash': 'SHA-256'
        },
        passkey,
        256
    );

    let derivedKey = await window.crypto.subtle.deriveKey(
        {
            'name': 'PBKDF2',
            'salt': salt,
            'iterations': 10000,
            'hash': 'SHA-256'
        },
        passkey,
        {
            'name': 'AES-GCM',
            'length': 256
        },
        true,
        ['encrypt', 'decrypt']);
    let nonce = await window.crypto.getRandomValues(new Uint8Array(12));

    let ciphertext = await window.crypto.subtle.encrypt(
        {
            'name': 'AES-GCM',
            'iv': nonce
        },
        derivedKey,
        plaintext
    );

    let payload = encodeBuffer(nonce) + encodeBuffer(ciphertext);

    return [salt, verifier, payload];
}

async function createSecret(event) {
    event.preventDefault();

    if (passphraseFld.value === '' || secretFld.value === '') {
        renderError('You forgot your secret or your passphrase!');
        return false;
    }

    let [salt, verifier, payload] = await encrypt();
    let creationString = encodeBuffer(salt) + '$' + encodeBuffer(verifier) + '$' + payload;

    const response = await fetch('/create', {
        method: 'POST',
        cache: 'no-cache',
        headers: {
            'Content-Type': 'text/plain'
        },
        body: creationString
    });

    switch(response.status) {
        case 201:
            let code = await response.text();

            let message = '<p>Congratulations! Your secret is safe.</p>';
            message += '<p>Your secret ID is <b>' + code + '</b><br />';
            message += 'Your passphrase is <b>' + passphraseFld.value + '</b></p>';
            message += '<p><a href="/secret/' + code + '">Or use this link to retrieve it.</a></p>';

            let successMessage = document.getElementById('success');
            successMessage.innerHTML = message;

            successModal.show();
            break;
        case 413:
            renderError('Your secret was too large for the server to keep it.');
            break;
        default:
            renderError('Something broke, but we don\'t know exactly what ...');
    }

    passphraseFld.value = '';
    secretFld.value = '';
}

submitBtn.addEventListener('click', createSecret, true);