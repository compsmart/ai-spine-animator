#!/usr/bin/env python3
"""Spine Studio - AI anchor refinement using Google Gemini Vision."""

import sys
import json
import argparse
import math
import os
from pathlib import Path


def get_api_key():
    """Load Gemini API key from vault."""
    vault_path = Path(__file__).resolve().parent.parent / 'vault' / 'gemini_key.txt'
    try:
        if vault_path.exists():
            return vault_path.read_text().strip()
    except Exception:
        pass
    return os.environ.get('GOOGLE_API_KEY') or os.environ.get('GEMINI_API_KEY')


def refine_anchors(image_path, anchors_json, model='gemini-3-pro-preview'):
    """Annotate image with anchor markers and ask Gemini to correct positions."""
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
        if not anchors:
            return {"status": "error", "message": "No anchors provided"}

        img = Image.open(image_path).convert('RGBA')
        w, h = img.size

        # Draw anchor markers on a copy
        annotated = img.copy()
        draw = ImageDraw.Draw(annotated)

        circle_radius = max(6, int(min(w, h) * 0.015))
        font_size = max(10, int(min(w, h) * 0.02))

        # Try to load a readable font
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

        # Color scheme: green=root, indigo=joint, orange=tip
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

            # Draw filled circle with white outline
            bbox = [
                ax - circle_radius, ay - circle_radius,
                ax + circle_radius, ay + circle_radius
            ]
            draw.ellipse(bbox, fill=color, outline=(255, 255, 255), width=2)

            # Draw label above the circle
            label = anchor.get('label', anchor.get('id', '?'))
            draw.text(
                (ax, ay - circle_radius - font_size - 2),
                label, fill=(255, 255, 255), font=font, anchor='ms'
            )

        # Send annotated image to Gemini for correction
        client = genai.Client(api_key=api_key)

        anchor_list_str = "\n".join(
            f"  - \"{a.get('label', a['id'])}\" (id={a['id']}): currently at x={a['x']:.4f}, y={a['y']:.4f}"
            for a in anchors
        )

        prompt = f"""Look at this image. Colored dots and labels have been drawn at the current anchor positions.
Each labeled dot represents an anchor point for skeletal animation.

Current anchor positions (normalized 0-1, where 0,0 = top-left, 1,1 = bottom-right):
{anchor_list_str}

For each anchor, evaluate whether the dot is placed at the correct anatomical/structural position on the image.
If a dot is NOT in the right place, provide corrected x,y coordinates.
If a dot IS correctly placed, return its current coordinates unchanged.

Return a JSON object with this exact structure:
{{
  "anchors": [
    {{
      "id": "anchor_id",
      "x": 0.5,
      "y": 0.5,
      "corrected": true
    }}
  ]
}}

Rules:
- All x,y coordinates must be normalized 0-1 (0,0 = top-left, 1,1 = bottom-right)
- Set "corrected" to true if you moved the anchor, false if it was already correct
- Return ALL anchors, not just corrected ones
- Be precise - place anchors exactly on the anatomical joint/feature they represent

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

        if 'anchors' not in data or not data['anchors']:
            return {"status": "error", "message": "AI did not return anchor corrections"}

        # Build result with movement metrics
        corrected_anchors = data['anchors']
        corrected_map = {a['id']: a for a in corrected_anchors}

        total_movement = 0.0
        corrected_count = 0
        result_anchors = []

        for orig in anchors:
            aid = orig['id']
            corrected = corrected_map.get(aid)
            if corrected:
                new_x = max(0.0, min(1.0, float(corrected.get('x', orig['x']))))
                new_y = max(0.0, min(1.0, float(corrected.get('y', orig['y']))))
                dist = math.sqrt((new_x - orig['x']) ** 2 + (new_y - orig['y']) ** 2)
                total_movement += dist
                if corrected.get('corrected', False) or dist > 0.005:
                    corrected_count += 1
                result_anchors.append({
                    'id': aid,
                    'x': new_x,
                    'y': new_y,
                    'corrected': corrected.get('corrected', dist > 0.005)
                })
            else:
                # Anchor not returned by AI, keep original
                result_anchors.append({
                    'id': aid,
                    'x': orig['x'],
                    'y': orig['y'],
                    'corrected': False
                })

        return {
            "status": "success",
            "anchors": result_anchors,
            "corrected_count": corrected_count,
            "total_movement": round(total_movement, 6)
        }

    except json.JSONDecodeError as e:
        return {"status": "error", "message": f"Failed to parse AI response as JSON: {str(e)}"}
    except Exception as e:
        return {"status": "error", "message": str(e)}


def main():
    parser = argparse.ArgumentParser(description="Refine anchor positions using Gemini Vision")
    parser.add_argument("--image", required=True, help="Path to image file")
    parser.add_argument("--anchors", required=True, help="JSON string of current anchor positions")
    parser.add_argument("--model", default="gemini-3-pro-preview", help="Gemini model to use")
    args = parser.parse_args()

    result = refine_anchors(args.image, args.anchors, model=args.model)
    print(json.dumps(result))

    if result.get("status") == "error":
        sys.exit(1)


if __name__ == "__main__":
    main()
