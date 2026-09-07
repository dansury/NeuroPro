# OLE Compound File (.pak) to JSON Converter

This tool converts `.pak` files (OLE Compound Files) used by `egoscop.ru` into a readable JSON format.

## Features
- **Readable Russian Text**: Automatically extracts and decodes Russian text (CP1251) from the binary streams.
- **Data Integrity**: Stores the raw hexadecimal data for every stream, ensuring no information is lost.
- **Structured Output**: Organizes the internal OLE storage structure into a flat, searchable JSON dictionary.

## Files
- `pak2json.py`: The converter script.
- `SMU_final.json`: The converted output of your provided `SMU.pak` file.

## Usage
To convert a `.pak` file to JSON:
```bash
python3 pak2json.py <input_file.pak> <output_file.json>
```

## Note on Reverse Conversion
Converting JSON back to `.pak` is technically restricted by the OLE format's complexity. While content can be modified, the size of each data stream must remain identical to the original to maintain the file's structural integrity.
