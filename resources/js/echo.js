import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const readMeta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;

const reverbPort = readMeta('reverb-port');
const reverbScheme = readMeta('reverb-scheme');

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: readMeta('reverb-app-key'),
    wsHost: readMeta('reverb-host'),
    wsPort: reverbPort ? Number(reverbPort) : 80,
    wssPort: reverbPort ? Number(reverbPort) : 443,
    forceTLS: (reverbScheme ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
