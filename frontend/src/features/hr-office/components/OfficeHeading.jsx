import './officeSurface.css'
export default function OfficeHeading({ title, code, directorate, children }) {
  return <div className="office-heading"><div><p className="text-[11px] text-text-light mb-2">الشؤون الإدارية / {directorate}</p><div className="flex items-center gap-3"><h1>{title}</h1><span className="office-code">رمز <b>{code}</b></span></div></div><div className="flex flex-wrap gap-2">{children}</div></div>
}
