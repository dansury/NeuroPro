import json
import os
import sys
import re

try:
    import olefile
except ImportError:
    print("\n[!] Error: The 'olefile' library is not installed.")
    print("[+] Please install it by running the following command in your terminal/PowerShell:")
    print("\n    pip install olefile\n")
    sys.exit(1)

def extract_readable_text(data):
    """
    Extracts readable CP1251 text from bytes.
    Handles the specific structure where text is often preceded by length bytes
    and followed by binary markers like 'b8 0b' (which decodes to 'ё' in CP1251).
    """
    if not data:
        return None

    try:
        # The format often has a 4-byte length or similar header.
        # We'll look for the longest printable sequence in CP1251.
        decoded = data.decode('cp1251', errors='ignore')

        # Heuristic: The 'ё' (b8) and other artifacts usually appear at the end
        # of a string followed by nulls or other binary data.
        # We'll use a regex to find sequences of Cyrillic, Latin, digits, and punctuation.
        # This excludes control characters and common binary markers.

        # Pattern: Match sequences of common characters, excluding the 'ё' if it's isolated at the end
        # or followed by non-text bytes.
        matches = re.findall(r'[a-zA-Zа-яА-Я0-9\s\.\,\-\_\(\)\:\!\?\/]+', decoded)

        if not matches:
            return None

        # Pick the longest match as the primary content
        best_match = max(matches, key=len).strip()

        # If the match is too short compared to the data, it might be noise
        if len(best_match) < 2 and len(data) > 4:
            return None

        return best_match
    except:
        return None

def pak_to_json(pak_path, json_path):
    if not olefile.isOleFile(pak_path):
        print(f"Error: {pak_path} is not a valid OLE file.")
        return

    data_dict = {}

    with olefile.OleFileIO(pak_path) as ole:
        for entry in ole.listdir(streams=True, storages=False):
            stream_path = "/".join(entry)
            stream_data = ole.openstream(entry).read()

            item = {
                "size_bytes": len(stream_data),
                "content_text": extract_readable_text(stream_data),
                "raw_hex": stream_data.hex(' ')
            }

            # Remove text field if it's empty or useless
            if not item["content_text"]:
                del item["content_text"]

            data_dict[stream_path] = item

    # Sort by path for better organization
    sorted_data = {k: data_dict[k] for k in sorted(data_dict.keys())}

    with open(json_path, 'w', encoding='utf-8') as f:
        json.dump(sorted_data, f, ensure_ascii=False, indent=4)
    print(f"Successfully converted {pak_path} to {json_path}")

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python pak2json.py <input.pak> <output.json>")
    else:
        pak_to_json(sys.argv[1], sys.argv[2])
