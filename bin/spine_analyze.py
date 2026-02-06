#!/usr/bin/env python3
"""Spine Studio - AI-powered skeletal analysis using Google Gemini Vision."""

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


def analyze_image(image_path, model='gemini-3-pro-preview'):
    """Analyze an image with Gemini Vision to detect anchor points and suggest animations."""
    try:
        from google import genai
        from google.genai import types
        from PIL import Image

        api_key = get_api_key()
        if not api_key:
            return {"status": "error", "message": "No Gemini API key found"}

        if not os.path.exists(image_path):
            return {"status": "error", "message": f"Image not found: {image_path}"}

        client = genai.Client(api_key=api_key)
        img = Image.open(image_path)

        prompt = """Analyze this 2D image for skeletal animation. Return a JSON object with this exact structure:

{
  "image_type": "face|body|animal|character|object",
  "description": "Brief description of the image content",
  "anchors": [
    {
      "id": "anchor_name",
      "label": "Human readable name",
      "x": 0.5,
      "y": 0.5,
      "type": "root|joint|tip"
    }
  ],
  "bones": [
    {
      "id": "bone_name",
      "from": "anchor_id",
      "to": "anchor_id",
      "parent": null
    }
  ],
  "animations": [
    {
      "name": "Animation Name",
      "description": "What this animation does",
      "duration": 1.0,
      "loop": true,
      "keyframes": [
        {
          "time": 0.0,
          "anchors": {
            "anchor_id": {"rotation": 0, "translateX": 0.0, "translateY": 0.0, "scale": 1.0}
          }
        },
        {
          "time": 0.5,
          "anchors": {
            "anchor_id": {"rotation": 15, "translateX": 0.01, "translateY": -0.02, "scale": 1.0}
          }
        },
        {
          "time": 1.0,
          "anchors": {
            "anchor_id": {"rotation": 0, "translateX": 0.0, "translateY": 0.0, "scale": 1.0}
          }
        }
      ]
    }
  ]
}

CRITICAL coordinate rules:
- All anchor x,y coordinates are normalized 0.0 to 1.0 where (0,0) = top-left corner and (1,1) = bottom-right corner
- x=0.0 means left edge, x=1.0 means right edge, x=0.5 means horizontal center
- y=0.0 means top edge, y=1.0 means bottom edge, y=0.5 means vertical center
- Be PRECISE: place each anchor exactly on the pixel location of the feature it represents
- Place anchors at natural joints/pivot points visible in the image
- For faces: eyes, eyebrows, nose, mouth corners, chin, forehead, ears
- For bodies: head, neck, shoulders, elbows, wrists, hips, knees, ankles
- For animals: head, neck, spine segments, leg joints, tail
- For objects: natural pivot/hinge points
- Create a logical bone hierarchy (parent-child relationships)
- The first anchor should be the root (type: "root"), typically the center/base
- Provide 3-5 context-appropriate animations
- Animations should use subtle, natural-looking movements (rotations in degrees, translations as normalized fractions)
- Keep rotation values between -45 and 45 degrees for natural motion
- translateX/Y are NORMALIZED fractions of image dimensions (0.02 = move right by 2% of image width)
- Keep translateX between -0.05 and 0.05 for subtle natural motion
- Keep translateY between -0.05 and 0.05 for subtle natural motion
- Each animation needs at least 3 keyframes (start, middle, end)
- Ensure keyframes at time 0 and 1.0 match for smooth looping

Return ONLY the JSON object, no other text."""

        config = types.GenerateContentConfig(
            response_mime_type='application/json'
        )

        response = client.models.generate_content(
            model=model,
            contents=[prompt, img],
            config=config
        )

        result_text = response.text.strip()

        # Parse and validate the response
        data = json.loads(result_text)

        # Validate required fields
        if 'anchors' not in data or not data['anchors']:
            return {"status": "error", "message": "AI did not detect any anchor points"}
        if 'bones' not in data or not data['bones']:
            return {"status": "error", "message": "AI did not detect any bones"}
        if 'animations' not in data or not data['animations']:
            return {"status": "error", "message": "AI did not generate any animations"}

        # Validate anchor coordinates are in 0-1 range
        for anchor in data['anchors']:
            anchor['x'] = max(0.0, min(1.0, float(anchor.get('x', 0.5))))
            anchor['y'] = max(0.0, min(1.0, float(anchor.get('y', 0.5))))
            if 'type' not in anchor:
                anchor['type'] = 'joint'
            if 'id' not in anchor:
                anchor['id'] = anchor.get('label', 'anchor').lower().replace(' ', '_')

        # Validate bones reference valid anchors
        anchor_ids = {a['id'] for a in data['anchors']}
        valid_bones = []
        for bone in data['bones']:
            if bone.get('from') in anchor_ids and bone.get('to') in anchor_ids:
                valid_bones.append(bone)
        data['bones'] = valid_bones

        if not data['bones']:
            return {"status": "error", "message": "No valid bones after validation"}

        # Validate and clamp animation translateX/Y to normalized range
        for anim in data.get('animations', []):
            for kf in anim.get('keyframes', []):
                for anchor_id, transform in kf.get('anchors', {}).items():
                    tx = transform.get('translateX', 0)
                    ty = transform.get('translateY', 0)
                    # Detect if AI returned pixel values instead of normalized
                    if abs(tx) > 1.0 or abs(ty) > 1.0:
                        import sys
                        print(f"WARNING: translateX/Y values appear to be pixels, not normalized. "
                              f"anchor={anchor_id} tx={tx} ty={ty}. Converting by dividing by image size.",
                              file=sys.stderr)
                        # Approximate conversion: assume ~500px image dimension
                        tx = tx / 500.0
                        ty = ty / 500.0
                    transform['translateX'] = max(-0.1, min(0.1, float(tx)))
                    transform['translateY'] = max(-0.1, min(0.1, float(ty)))

        return {
            "status": "success",
            "image_type": data.get('image_type', 'unknown'),
            "description": data.get('description', ''),
            "anchors": data['anchors'],
            "bones": data['bones'],
            "animations": data['animations']
        }

    except json.JSONDecodeError as e:
        return {"status": "error", "message": f"Failed to parse AI response as JSON: {str(e)}"}
    except Exception as e:
        return {"status": "error", "message": str(e)}


def main():
    parser = argparse.ArgumentParser(description="Analyze images for skeletal animation using Gemini Vision")
    parser.add_argument("--image", required=True, help="Path to image file to analyze")
    parser.add_argument("--model", default="gemini-3-pro-preview", help="Gemini model to use")
    args = parser.parse_args()

    result = analyze_image(args.image, model=args.model)
    print(json.dumps(result))

    if result.get("status") == "error":
        sys.exit(1)


if __name__ == "__main__":
    main()
