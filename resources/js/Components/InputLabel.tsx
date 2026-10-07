import { RequiredMark } from '@/Components/shared/RequiredMark';
import { LabelHTMLAttributes } from 'react';

export default function InputLabel({
    value,
    className = '',
    children,
    required,
    ...props
}: LabelHTMLAttributes<HTMLLabelElement> & {
    value?: string;
    /** Sprint 16 — red asterisk; true when the Form Request rule is `required`. */
    required?: boolean;
}) {
    return (
        <label
            {...props}
            className={
                `block text-sm font-medium text-foreground ` +
                className
            }
        >
            {value ? value : children}
            {required && <> <RequiredMark /></>}
        </label>
    );
}
