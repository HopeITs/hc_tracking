/**
 * A failed Livewire request (a 5xx) shows one plain toast instead of
 * Livewire's error dialog (M-05). The server sends the reference id in the
 * X-Error-Reference header and never the exception text; the wording lives in
 * lang/{en,ar}/errors.php and is read from the <body> data attributes, so no
 * sentence is written here.
 */
document.addEventListener('livewire:init', () => {
    const body = document.body;

    const say = (reference) => {
        const text = body.dataset.requestFailed;

        if (! text) {
            return;
        }

        const withReference = reference && body.dataset.requestReference
            ? `${text} ${body.dataset.requestReference.replace(':reference', reference)}`
            : text;

        window.Flux?.toast({ text: withReference, variant: 'danger', duration: 10000 });
    };

    window.Livewire.interceptRequest(({ onError, onFailure }) => {
        onError(({ response, preventDefault }) => {
            if (response.status < 500) {
                return;
            }

            preventDefault();
            say(response.headers.get('X-Error-Reference'));
        });

        onFailure(() => say(null));
    });
});
