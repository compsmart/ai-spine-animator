#!/usr/bin/env python3
"""Spine Studio - Regenerate animations using Google Gemini Vision with current anchor positions."""

import sys
import json
import argparse
import os
from pathlib import Path


def get_api_key():
    """Load Gemini API key from vault."""
    vault_paths = [
        '/var/www/evo/vault/gemini_key.txt',
        '/root/.openclaw/workspace/vault/gemini_key.txt'
    ]
    for vault_path in vault_paths:
        try:
            if os.path.exists(vault_path):
                with open(vault_path, 'r') as f:
                    return f.read().strip()
        except Exception:
            continue
    return os.environ.get('GOOGLE_API_KEY') or os.environ.get('GEMINI_API_KEY')


def update_animations(image_path, anchors_json, bones_json, model='gemini-3-pro-preview'):
    """Send image + current anchors/bones to Gemini and ask for new animations."""
    try:
        from google import genai
        from google.genai import types
        from PIL import Image, ImageDraw, ImageFont

        api_key = get_api_key()
        if not api_key:
            return {"status": "error", "message": "No Gemini API key found"}

        if not os.path.exists(image_path):
            return {"status": "error", "message": f"Image not found: {image_path}"}

        anchors = json.loads(anchors_json)
        bones = json.loads(bones_json)

        if not anchors:
            return {"status": "error", "message": "No anchors provided"}

        img = Image.open(image_path).convert('RGBA')
        w, h = img.size

        # Draw anchor markers on a copy for visual reference
        annotated = img.copy()
        draw = ImageDraw.Draw(annotated)

        circle_radius = max(6, int(min(w, h) * 0.015))
        font_size = max(10, int(min(w, h) * 0.02))

        font = None
        font_paths = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        ]
        for fp in font_paths:
            try:
                font = ImageFont.truetype(fp, font_size)
                break
            except (IOError, OSError):
                continue
        if font is None:
            font = ImageFont.load_default()

        colors = {
            'root': (34, 197, 94),
            'joint': (99, 102, 241),
            'tip': (251, 146, 60),
        }

        for anchor in anchors:
            ax = anchor['x'] * w
            ay = anchor['y'] * h
            anchor_type = anchor.get('type', 'joint')
            color = colors.get(anchor_type, colors['joint'])

            bbox = [
                ax - circle_radius, ay - circle_radius,
                ax + circle_radius, ay + circle_radius
            ]
            draw.ellipse(bbox, fill=color, outline=(255, 255, 255), width=2)

            label = anchor.get('label', anchor.get('id', '?'))
            draw.text(
                (ax, ay - circle_radius - font_size - 2),
                label, fill=(255, 255, 255), font=font, anchor='ms'
            )

        # Draw bones
        for bone in bones:
            from_anchor = next((a for a in anchors if a['id'] == bone.get('from')), None)
            to_anchor = next((a for a in anchors if a['id'] == bone.get('to')), None)
            if from_anchor and to_anchor:
                fx, fy = from_anchor['x'] * w, from_anchor['y'] * h
                tx, ty = to_anchor['x'] * w, to_anchor['y'] * h
                draw.line([(fx, fy), (tx, ty)], fill=(251, 191, 36), width=2)

        client = genai.Client(api_key=api_key)

        anchor_list_str = "\n".join(
            f"  - \"{a.get('label', a['id'])}\" (id={a['id']}, type={a.get('type', 'joint')}): x={a['x']:.4f}, y={a['y']:.4f}"
            for a in anchors
        )

        bone_list_str = "\n".join(
            f"  - \"{b['id']}\": from={b['from']} to={b['to']}"
            for b in bones
        )

        prompt = f"""Look at this image with its skeletal anchor points and bones drawn on it.

Current anchor positions (normalized 0-1, where 0,0 = top-left, 1,1 = bottom-right):
{anchor_list_str}

Bone connections:
{bone_list_str}

Generate 3-5 context-appropriate animations for this character/image. Each animation should use the existing anchor IDs.

Return a JSON object with this exact structure:
{{
  "animations": [
    {{
      "name": "Animation Name",
      "description": "What this animation does",
      "duration": 1.0,
      "loop": true,
      "keyframes": [
        {{
          "time": 0.0,
          "anchors": {{
            "anchor_id": {{"rotation": 0, "translateX": 0.0, "translateY": 0.0, "scale": 1.0}}
          }}
        }},
        {{
          "time": 0.5,
          "anchors": {{
            "anchor_id": {{"rotation": 15, "translateX": 0.01, "translateY": -0.02, "scale": 1.0}}
          }}
        }},
        {{
          "time": 1.0,
          "anchors": {{
            "anchor_id": {{"rotation": 0, "translateX": 0.0, "translateY": 0.0, "scale": 1.0}}
          }}
        }}
      ]
    }}
  ]
}}

Rules:
- translateX/Y are NORMALIZED fractions of image dimensions (0.02 = move right by 2% of image width)
- Keep translateX between -0.05 and 0.05 for subtle natural motion
- Keep translateY between -0.05 and 0.05 for subtle natural motion
- Keep rotation values between -45 and 45 degrees for natural motion
- Each animation needs at least 3 keyframes (start, middle, end)
- Ensure keyframes at time 0 and 1.0 match for smooth looping
- Create animations appropriate for the character (e.g., breathing, blinking, waving, idle sway)
- Only reference anchor IDs that exist in the list above

Return ONLY the JSON object, no other text."""

        config = types.GenerateContentConfig(
            response_mime_type='application/json'
        )

        response = client.models.generate_content(
            model=model,
            contents=[prompt, annotated],
            config=config
        )

        result_text = response.text.strip()
        data = json.loads(result_text)

        if 'animations' not in data or not data['animations']:
            return {"status": "error", "message": "AI did not generate any animations"}

        # Validate and clamp animation translateX/Y to normalized range
        anchor_ids = {a['id'] for a in anchors}
        for anim in data['animations']:
            for kf in anim.get('keyframes', []):
                # Remove references to non-existent anchors
                valid_anchors = {}
                for anchor_id, transform in kf.get('anchors', {}).items():
                    if anchor_id not in anchor_ids:
                        continue
                    tx = transform.get('translateX', 0)
                    ty = transform.get('translateY', 0)
                    # Detect pixel values
                    if abs(tx) > 1.0 or abs(ty) > 1.0:
                        print(f"WARNING: translateX/Y appear to be pixels. "
                              f"anchor={anchor_id} tx={tx} ty={ty}. Converting.",
                              file=sys.stderr)
                        tx = tx / 500.0
                        ty = ty / 500.0
                    transform['translateX'] = max(-0.1, min(0.1, float(tx)))
                    transform['translateY'] = max(-0.1, min(0.1, float(ty)))
                    valid_anchors[anchor_id] = transform
                kf['anchors'] = valid_anchors

        return {
            "status": "success",
            "animations": data['animations']
        }

    except json.JSONDecodeError as e:
        return {"status": "error", "message": f"Failed to parse AI response as JSON: {str(e)}"}
    except Exception as e:
        return {"status": "error", "message": str(e)}


def main():
    parser = argparse.ArgumentParser(description="Regenerate animations using Gemini Vision")
    parser.add_argument("--image", required=True, help="Path to image file")
    parser.add_argument("--anchors", required=True, help="JSON string of current anchor positions")
    parser.add_argument("--bones", required=True, help="JSON string of bone connections")
    parser.add_argument("--model", default="gemini-3-pro-preview", help="Gemini model to use")
    args = parser.parse_args()

    result = update_animations(args.image, args.anchors, args.bones, model=args.model)
    print(json.dumps(result))

    if result.get("status") == "error":
        sys.exit(1)


if __name__ == "__main__":
    main()
