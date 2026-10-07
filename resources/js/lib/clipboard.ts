/**
 * Sprint 17 Sub 01 — copy text that also works on plain HTTP.
 *
 * `navigator.clipboard` only exists in a secure context (HTTPS or
 * localhost); on `http://web-daiku-interior.test` or an HTTP staging server
 * it is undefined. There we fall back to a hidden `<textarea>` +
 * `document.execCommand('copy')` (deprecated, but still the only option
 * outside a secure context). Resolves `false` when both fail, so callers
 * can tell the user instead of failing silently.
 */
export async function copyText(text: string): Promise<boolean> {
    if (typeof window !== 'undefined' && window.isSecureContext && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // Permission denied / document not focused — try the fallback below.
        }
    }

    return copyWithTextarea(text);
}

function copyWithTextarea(text: string): boolean {
    if (typeof document === 'undefined') {
        return false;
    }

    const active = document.activeElement as HTMLElement | null;
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    // Off-screen but still selectable (display:none can't be selected); font-size
    // ≥16px stops iOS from zooming in when it is focused.
    textarea.style.position = 'fixed';
    textarea.style.top = '0';
    textarea.style.left = '-9999px';
    textarea.style.opacity = '0';
    textarea.style.fontSize = '16px';
    document.body.appendChild(textarea);

    let copied = false;
    try {
        textarea.focus();
        textarea.select();
        textarea.setSelectionRange(0, text.length);
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    } finally {
        document.body.removeChild(textarea);
        active?.focus?.();
    }

    return copied;
}
