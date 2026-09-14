import { brand } from "@/lib/brand";
import { CampaignsHubMark } from "./CampaignsHubMark";

/**
 * BRAND-LOCKUP-001 — the CampaignsHub logo, built once and read by every surface.
 *
 * The wordmark is TEXT, not artwork. A picture of the logo would need one file per language, per
 * theme and per size — «logo-ar.png، logo-white.svg، logo-report.png» — which is the sprawl the
 * identity document exists to prevent, and the reason it ships the symbol as a colourable SVG and
 * the words as words.
 *
 * Three variants, because the surfaces genuinely differ:
 *   `full`    — mark, name and the lockup line. Sign-in, landing, report headers.
 *   `compact` — mark and name. An expanded sidebar, where a strapline is noise.
 *   `mark`    — the symbol alone. A collapsed rail, a favicon-sized slot, an attribution row.
 *
 * This is the PLATFORM's identity. It does not decide whose brand leads a client report:
 * BRANDING-HIERARCHY-001 resolves Platform → Agency → Client for that, and on a client-facing
 * report CampaignsHub appears as the secondary credit rather than over the client's own mark.
 */
export function CampaignsHubLogo({
  locale = "en",
  variant = "full",
  size = "md",
  href,
  className = "",
}: {
  locale?: "ar" | "en";
  variant?: "full" | "compact" | "mark";
  size?: "sm" | "md" | "lg";
  /** Omitted where the logo is not a way out of the page — a print header, an app rail already linked. */
  href?: string;
  className?: string;
}) {
  const ar = locale === "ar";
  const s = SIZES[size];
  const name = ar ? brand.lockup.nameAr : brand.lockup.nameEn;
  const line = ar ? brand.lockup.lineAr : brand.lockup.lineEn;

  const body =
    variant === "mark" ? (
      <CampaignsHubMark
        size={s.mark}
        className="text-brand-mark"
        title={name}
      />
    ) : (
      /*
        The mark sits on the LEFT in both languages, which RTL will not do on its own.
        
        The identity draws it that way in Arabic and in English alike — text beside a mark that stays
        put — and an `inline-flex` inside `dir="rtl"` lays its first child on the RIGHT, so the Arabic
        lockup rendered mirrored wherever the app is in Arabic, which is most of it. Measured before
        this: the mark's box at x=1180 with the name's at x=1073, the mark to the RIGHT of the words.
        
        `dir="ltr"` pins the ROW only. The Arabic inside still shapes and reads right-to-left, because
        that is a property of the text and not of the box it sits in.
      */
      <span className={`inline-flex items-center gap-3 ${className}`} dir="ltr">
        <CampaignsHubMark size={s.mark} className="shrink-0 text-brand-mark" />
        {/* The words hug the mark: start-aligned in English, end-aligned in Arabic, as the identity sets them. */}
        <span
          className={`flex flex-col ${ar ? "items-end text-end" : "items-start text-start"}`}
          dir={ar ? "rtl" : "ltr"}
        >
          <span
            className={`${s.title} font-heading font-extrabold leading-none tracking-tight`}
          >
            {/*
              Split so the second half carries the mark's colour, as the identity draws it —
              «Campaigns» in the text colour and «Hub» in the brand green, and the Arabic mirrored
              so «كامبينز» is green and «هب» is the text colour.
            */}
            {ar ? (
              <>
                <span className="text-brand-mark">{"كامبينز "}</span>
                <span className="text-text-primary">{"هب"}</span>
              </>
            ) : (
              <>
                <span className="text-text-primary">Campaigns</span>
                <span className="text-brand-mark">Hub</span>
              </>
            )}
          </span>
          {variant === "full" && (
            <span
              className={`${s.line} mt-1 text-text-muted ${ar ? "" : "uppercase tracking-[0.28em]"}`}
              data-testid="brand-lockup-line"
            >
              {line}
            </span>
          )}
        </span>
      </span>
    );

  if (!href) {
    return (
      <span
        className="inline-flex items-center"
        data-testid="campaignshub-logo"
      >
        {body}
      </span>
    );
  }

  const external = /^https?:/i.test(href);

  return (
    <a
      href={href}
      data-testid="campaignshub-logo"
      aria-label={name}
      className="inline-flex items-center"
      {...(external ? { target: "_blank", rel: "noopener noreferrer" } : {})}
    >
      {body}
    </a>
  );
}

const SIZES = {
  sm: { mark: 28, title: "text-[17px]", line: "text-[9px]" },
  md: { mark: 36, title: "text-[22px]", line: "text-[10px]" },
  lg: { mark: 48, title: "text-[30px]", line: "text-[12px]" },
} as const;
