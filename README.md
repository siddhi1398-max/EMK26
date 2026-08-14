# EM Karnataka 2026 — Conference Website

Live site: [your GitHub Pages URL here]

## Cashfree registration payments

The registration page now creates a Cashfree Payment Link and gives the delegate two choices: scan Cashfree's QR code or open/copy the secure payment link. Payment status is verified server-side before a registration ID is issued.

1. Rotate any Cashfree secret that has been shared in chat or source code.
2. Copy `.env.example` to `.env`.
3. Add the new sandbox App ID and Secret Key to `.env`; set `APP_URL` to the URL where this folder is served.
4. Serve the project through Apache/PHP (for example, `http://localhost/EMK26/`), not as a `file://` page.
5. In production, set `CASHFREE_ENV=production`, use production credentials, enable HTTPS, and ensure the public domain is configured in Cashfree.

Pending and paid registration records are stored in `data/registrations.json`. That file and `.env` are ignored by Git; the `data` directory is denied over Apache HTTP.

### Gmail email notifications

After Cashfree confirms full payment, the backend sends:

- a purchase acknowledgment and Registration ID to the delegate;
- a detailed paid-registration notification to the configured administrator.

Add the `SMTP_*`, `MAIL_*`, and `ADMIN_*` values from `.env.example` to `.env`. For Gmail, enable 2-Step Verification and create a 16-character Google App Password; put that App Password in `SMTP_APP_PASSWORD`. Do not use or commit the normal Gmail password. Failed deliveries are logged and retried during subsequent payment-status checks, up to five attempts per recipient.

The generated Cashfree Payment Link includes a signed webhook URL at `api/payment.php?action=webhook`. This allows notifications to run even when the payer closes the browser after payment. Cashfree webhook signatures and timestamps are verified before a registration is marked paid. In production, `APP_URL` must be the public HTTPS site URL so Cashfree can reach the endpoint.

### Google Sheets registration tracking

Every confirmed registration can be appended to a `Registrations` sheet with the Registration ID, payment status, amount paid, registration tier, workshops, competitions, attendee details, Cashfree reference, and timestamps. Registration IDs are checked before appending, and sync failures retry up to five times.

1. Create a Google Cloud project and enable the Google Sheets API.
2. Create a service account and download its JSON key.
3. Save the key as `config/google-service-account.json`.
4. Create a Google Sheet with a tab named `Registrations`.
5. Share that Sheet as **Editor** with the `client_email` found inside the service-account JSON.
6. Copy the spreadsheet ID from the Sheet URL and configure `.env`:

```env
GOOGLE_SHEETS_ENABLED=true
GOOGLE_SHEETS_SPREADSHEET_ID=your_spreadsheet_id
GOOGLE_SHEETS_RANGE=Registrations!A:U
GOOGLE_SERVICE_ACCOUNT_FILE=config/google-service-account.json
```

The first successful sync creates the column headers automatically. The service-account JSON is ignored by Git and the `config` directory is denied over Apache HTTP.

## 🚀 Quick Deploy (GitHub Pages)

```bash
git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/em-karnataka-2026.git
git push -u origin main
```
Then go to **Settings → Pages → Deploy from branch → main → / (root)**.

---

## 🖼️ How to Add / Replace Images

Drop your images into the `images/` folder and name them exactly as listed below.
All images have fallbacks — if a file is missing, the site still looks fine.

### Hero / Banner (slideshow at the top)
| File | Size | What it is |
|------|------|------------|
| `images/hero-1.jpg` | 1400×800px | Hero slide 1 — e.g. emergency department |
| `images/hero-2.jpg` | 1400×800px | Hero slide 2 — e.g. conference hall |
| `images/hero-3.jpg` | 1400×800px | Hero slide 3 — e.g. team/doctors |

### Section Cards (Home page)
| File | Size | What it is |
|------|------|------------|
| `images/workshops.jpg` | 600×400px | Hands-on workshops section |
| `images/conference.jpg` | 600×400px | Keynotes & sessions section |
| `images/research.jpg` | 600×400px | Abstract presentations section |
| `images/networking.jpg` | 600×400px | Networking section |
| `images/climate-em.jpg` | 600×400px | Conference theme section |
| `images/abstract-bg.jpg` | 1200×600px | Abstract submission CTA background |

### Logo
| File | Size | What it is |
|------|------|------------|
| `images/logo.png` | any (height ~36px) | Replaces the red cross in navbar |

### Speaker / Faculty Photos
| File | Size | What it is |
|------|------|------------|
| `images/speaker-1.jpg` | 300×300px | Keynote faculty photo |
| `images/speaker-2.jpg` | 300×300px | Workshop lead photo |
| `images/speaker-3.jpg` | 300×300px | Panelist photo |
| `images/speaker-4.jpg` | 300×300px | Workshop faculty photo |

> **Tip:** Keep images under 500KB. Compress at [squoosh.app](https://squoosh.app) before uploading.

---

## ✏️ Editing Content

Open `index.html` in any text editor or VS Code. Key things to change:

- **Conference date/venue** — search for `November 2026`
- **Registration prices** — search for `tier-price`
- **Speaker names** — search for `Speaker Name` / `Faculty Name`
- **Contact emails** — search for `emkarnataka2026.in`
- **Phone numbers** — search for `XXXXX XXXXX`
- **Countdown deadline** — search for `2026-08-31` in the script section

---

## 📁 File Structure

```
em-karnataka-2026/
├── index.html        ← entire website (single file)
├── README.md         ← this file
├── .gitignore
└── images/
    ├── logo.png
    ├── hero-1.jpg
    ├── hero-2.jpg
    ├── hero-3.jpg
    ├── workshops.jpg
    ├── conference.jpg
    ├── research.jpg
    ├── networking.jpg
    ├── climate-em.jpg
    ├── abstract-bg.jpg
    ├── speaker-1.jpg
    ├── speaker-2.jpg
    ├── speaker-3.jpg
    └── speaker-4.jpg
```
