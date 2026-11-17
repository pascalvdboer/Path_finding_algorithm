/**
 * Patch Studio UI — three self-made example designs (inline SVG, no brands). Loaded "as if uploaded".
 */
const SVG = (w, h, body) => `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">${body}</svg>`;

export const SAMPLES = [
  {
    id: 'club',
    file: 'voorbeeld-clublogo.svg',
    svg: SVG(800, 800,
      '<rect width="800" height="800" fill="#fff"/>' +
      '<circle cx="400" cy="400" r="380" fill="#0a1f44"/><circle cx="400" cy="400" r="350" fill="none" stroke="#f2b632" stroke-width="16"/>' +
      '<circle cx="400" cy="400" r="215" fill="#f2b632"/>' +
      '<path d="M400 245l42 96 104 9-79 69 24 102-91-55-91 55 24-102-79-69 104-9z" fill="#0a1f44"/>' +
      '<defs><path id="a" d="M140 400a260 260 0 01520 0"/><path id="b" d="M150 400a250 250 0 00500 0"/></defs>' +
      '<text font-family="Arial,Helvetica,sans-serif" font-weight="700" font-size="64" fill="#fff" letter-spacing="6"><textPath href="#a" startOffset="50%" text-anchor="middle">SPORTCLUB</textPath></text>' +
      '<text font-family="Arial,Helvetica,sans-serif" font-weight="700" font-size="52" fill="#fff" letter-spacing="4"><textPath href="#b" startOffset="50%" text-anchor="middle" dominant-baseline="hanging">SINDS 1975</textPath></text>'),
  },
  {
    id: 'shield',
    file: 'voorbeeld-schild.svg',
    svg: SVG(700, 820,
      '<path d="M350 20L670 110v260c0 210-140 350-320 430C170 720 30 580 30 370V110z" fill="#1f5aa6"/>' +
      '<path d="M350 52L640 134v236c0 190-126 318-290 392C186 688 60 560 60 370V134z" fill="#d2ebf0"/>' +
      '<circle cx="470" cy="270" r="58" fill="#f5a524"/>' +
      '<path d="M66 520l150-210 90 120 110-170 160 230 54 70v30L66 610z" fill="#24452f"/>' +
      '<path d="M216 310l40 56-30-6-22 24-20-30zM416 260l50 74-34-8-28 26-26-34z" fill="#fff"/>' +
      '<text x="350" y="690" font-family="Arial,Helvetica,sans-serif" font-weight="700" font-size="78" fill="#1f5aa6" text-anchor="middle">ALPINE</text>'),
  },
  {
    id: 'text',
    file: 'voorbeeld-tekstlogo.svg',
    svg: SVG(1000, 520,
      '<rect width="1000" height="520" fill="#fff"/>' +
      '<rect x="40" y="40" width="920" height="440" rx="70" fill="#b42318"/>' +
      '<rect x="72" y="72" width="856" height="376" rx="46" fill="none" stroke="#fff" stroke-width="22"/>' +
      '<text x="500" y="318" font-family="Arial Black,Arial,Helvetica,sans-serif" font-weight="900" font-size="190" fill="#fff" text-anchor="middle">TEAM 24</text>'),
  },
];

/** Example as a File, so it goes through exactly the same flow as an upload. */
export function sampleFile(s) {
  return new File([s.svg], s.file, { type: 'image/svg+xml' });
}

/** Data-URL thumbnail for the button. */
export function sampleThumb(s) {
  return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(s.svg);
}
