# EM Karnataka 2026 — Conference Website

Live site: [your GitHub Pages URL here]

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
