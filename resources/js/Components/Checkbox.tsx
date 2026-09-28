import { InputHTMLAttributes } from 'react';

export default function Checkbox({
    className = '',
    ...props
}: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-input text-daiku-yellow-dark shadow-xs focus:ring-ring/50 ' +
                className
            }
        />
    );
}
