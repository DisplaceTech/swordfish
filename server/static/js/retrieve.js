let secretId = window.location.href.split('/').slice(-1)[0];
if (secretId !== 'secret') {
    let secretContainer = document.getElementById('secretid');
    secretContainer.value = secretId;
}

let submitBtn = document.getElementById('retrieve');
let passphraseFld = document.getElementById('passphrase');
let secretFld = document.getElementById('secretid');

function fromHexString (hexString) {
    return new Uint8Array(hexString.match(/.{1,2}/g).map(byte => parseInt(byte, 16)));
}

async function decrypt(ciphertext, passkey) {
    let decoded = fromHexString(ciphertext);
    let salt = decoded.slice(0, 16);
    let nonce = decoded.slice(16, 28);
    let encrypted = decoded.slice(28);

    // Get salt
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

    // Get nonce
    return await window.crypto.subtle.decrypt(
        {
            'name': 'AES-GCM',
            'iv': nonce
        },
        derivedKey,
        encrypted
    );
}

async function retrieveSecret(event) {
    event.preventDefault();

    let secretText = document.getElementById('secret');
    secretText.value = '';

    if (passphraseFld.value === '' || secretFld.value === '') {
        renderError('You must enter a password and a secret ID!');
        return false;
    }

    let encoder = new TextEncoder();
    let decoder = new TextDecoder();
    let passphrase = encoder.encode(passphraseFld.value);
    let secret = secretFld.value;

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

    let payload = secret + '$' + encodeBuffer(verifier);
    const response = await fetch('/retrieve', {
        method: 'POST',
        cache: 'no-cache',
        headers: {
            'Content-Type': 'text/plain'
        },
        body: payload
    })

    switch(response.status) {
        case 200:
            let ciphertext = await response.text();

            let decoded = await decrypt(ciphertext, passkey);
            secretText.value = decoder.decode(decoded);
            break;
        case 401:
            renderError('Your password is incorrect!');
            break;
        case 404:
            renderError('That secret does not exist.');
            break;
        default:
            renderError('Something broke, but we don\'t know exactly what ...');
    }
}

submitBtn.addEventListener('click', retrieveSecret, true);