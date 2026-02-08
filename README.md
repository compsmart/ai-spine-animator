![Screenshot](spiderman.png)

[TRY IT HERE](https://spine.compsmart.cloud)

# Spine Studio

AI-powered 2D skeletal animation studio using Google Gemini Vision.

## What It Does

Upload a 2D image and the AI detects anchor points, builds a bone hierarchy, and
generates animations (breathing, idle sway, blinking) in the browser. Anchors can
be manually adjusted, refined by AI, and animations regenerated on demand.

## Setup

**1. Install Python dependencies:**
`pip install google-genai Pillow`

**2. Configure credentials** -- copy the example and add your real API key:

```bash
cp vault/gemini_key.txt.example vault/gemini_key.txt
# Edit vault/gemini_key.txt and replace the placeholder with your actual key
chmod 640 vault/gemini_key.txt
```

Alternatively, set the `GOOGLE_API_KEY` or `GEMINI_API_KEY` environment variable.

**3. Web server** -- serve the app directory with PHP enabled. Ensure `uploads/`
and `projects/` are writable by the web server user.

## Tech Stack

- **Frontend:** Single-page HTML/CSS/JS (`index.html`, all inline)
- **Backend:** PHP REST API (`api.php`) proxying to Python scripts
- **AI:** Google Gemini Vision API (model selectable from the UI)
- **Python 3.8+**, **PHP 7.4+** with `shell_exec`

## Directory Structure

```
spine-studio/
  index.html              # Frontend SPA
  api.php                 # REST API
  bin/
    spine_analyze.py      # AI skeletal analysis
    spine_refine.py       # AI anchor refinement
    spine_update_anims.py # AI animation regeneration
  vault/
    gemini_key.txt        # Your API key (git-ignored)
    gemini_key.txt.example# Template (tracked in git)
  uploads/                # Uploaded images (git-ignored)
  projects/               # Saved project JSON (git-ignored)
```

## License

MIT
