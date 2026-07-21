import { forwardRef, type TextareaHTMLAttributes } from 'react'
import { controlClass } from './Field'

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(
  function Textarea({ className = '', ...rest }, ref) {
    return <textarea ref={ref} className={`${controlClass} min-h-[88px] resize-y ${className}`} {...rest} />
  },
)
