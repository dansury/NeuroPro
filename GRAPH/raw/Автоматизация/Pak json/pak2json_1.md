import olefile
import json
import io
import os
import re
import sys

def extract_strings(data):
    """Extracts readable strings from binary data in both CP1251 and UTF-16LE."""
    results = []

    # Try CP1251
    try:
        text_cp = data.decode('cp1251', errors='ignore')
        # Look for Cyrillic sequences (minimum 4 characters)
        found_cp = re.findall(r'[А-ЯЁа-яё][а-яё\s\d\-\,\.\?\!\:\;]{3,}', text_cp)
        results.extend([s.strip() for s in found_cp if len(s.strip()) > 3])
    except:
        pass

    # Try UTF-16LE
    try:
        text_u16 = data.decode('utf-16le', errors='ignore')
        # Look for Cyrillic sequences in UTF-16
        found_u16 = re.findall(r'[А-ЯЁа-яё][а-яё\s\d\-\,\.\?\!\:\;]{3,}', text_u16)
        results.extend([s.strip() for s in found_u16 if len(s.strip()) > 3])
    except:
        pass

    # Remove duplicates while preserving order
    seen = set()
    return [x for x in results if not (x in seen or seen.add(x))]

def process_ole(ole_input, prefix=""):
    """Recursively processes an OLE file (or file-like object)."""
    results = {}

    for entry in ole_input.listdir(streams=True, storages=False):
        path = "/".join(entry)
        full_path = f"{prefix}/{path}" if prefix else path

        try:
            stream = ole_input.openstream(entry)
            data = stream.read()

            # Store raw data as hex for reconstruction
            results[full_path] = {
                "raw_hex": data.hex(' '),
                "size": len(data),
                "extracted_text": extract_strings(data)
            }

            # Check for nested OLE (common in these .pak files)
            # We check at offset 0 and offset 4
            for offset in [0, 4]:
                if len(data) > offset + 8:
                    header = data[offset:offset+8]
                    if header == b'\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1':
                        try:
                            with olefile.OleFileIO(io.BytesIO(data[offset:])) as nested_ole:
                                nested_results = process_ole(nested_ole, prefix=full_path)
                                results.update(nested_results)
                        except:
                            pass

        except Exception as e:
            print(f"Error processing {full_path}: {e}")

    return results

def main(input_pak, output_json):
    if not os.path.exists(input_pak):
        print(f"Error: File {input_pak} not found.")
        return

    print(f"Analyzing {input_pak}...")
    try:
        with olefile.OleFileIO(input_pak) as ole:
            data_map = process_ole(ole)

        with open(output_json, 'w', encoding='utf-8') as f:
            json.dump(data_map, f, ensure_ascii=False, indent=4)

        print(f"Success! Data saved to {output_json}")

        # Check for questions
        q_count = 0
        for path, info in data_map.items():
            for text in info["extracted_text"]:
                if "самостоятельно" in text or "решаю" in text:
                    q_count += 1

        if q_count > 0:
            print(f"Confirmed: Found {q_count} question-related strings!")
        else:
            print("Warning: Could not find specific test questions in the output.")

    except Exception as e:
        print(f"Failed to process OLE file: {e}")

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python pak2json.py <input.pak> <output.json>")
    else:
        main(sys.argv[1], sys.argv[2])
