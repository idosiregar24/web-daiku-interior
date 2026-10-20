import { useRef } from 'react';

/**
 * Sprint 28 K12 — a dialog opened by "the object is set" (`open={x !== null}`)
 * keeps showing the last object while its close animation runs, instead of
 * flashing "— Rp 0 untuk —" or the other variant's title. Read the value to
 * *render* through this; keep the live prop for `open` and for the submit.
 */
export function useLastDefined<T>(value: T | null | undefined): T | null | undefined {
    const last = useRef(value);

    if (value !== null && value !== undefined) {
        last.current = value;
    }

    return last.current;
}
