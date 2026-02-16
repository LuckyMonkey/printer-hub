#!/usr/bin/env python3
import argparse
import json
from math import floor

from reportlab.graphics import renderPDF
from reportlab.graphics.barcode import code128, qr, createBarcodeDrawing
from reportlab.graphics.shapes import Drawing
from reportlab.lib.pagesizes import letter
from reportlab.pdfgen import canvas

POINTS_PER_INCH = 72


def draw_code128(cnv, value, x, y, width, height):
    barcode = code128.Code128(value, barHeight=height * 0.62, barWidth=1.05)
    barcode_width = barcode.width
    x_offset = x + max((width - barcode_width) / 2, 0)
    y_offset = y + (height * 0.26)
    barcode.drawOn(cnv, x_offset, y_offset)
    cnv.setFont("Helvetica", 8)
    cnv.drawCentredString(x + width / 2, y + 4, value)


def draw_qr(cnv, value, x, y, width, height):
    widget = qr.QrCodeWidget(value)
    bounds = widget.getBounds()
    qr_width = bounds[2] - bounds[0]
    qr_height = bounds[3] - bounds[1]

    size = min(width * 0.68, height * 0.78)
    drawing = Drawing(size, size)
    drawing.add(widget)
    drawing.scale(size / qr_width, size / qr_height)

    x_offset = x + (width - size) / 2
    y_offset = y + (height - size) / 2 + 5
    renderPDF.draw(drawing, cnv, x_offset, y_offset)

    cnv.setFont("Helvetica", 8)
    cnv.drawCentredString(x + width / 2, y + 4, value)


def draw_upc(cnv, value, x, y, width, height):
    drawing = createBarcodeDrawing("UPCA", value=value, humanReadable=True)
    scale_x = width / drawing.width
    scale_y = (height * 0.78) / drawing.height
    scale = min(scale_x, scale_y)

    drawing.width *= scale
    drawing.height *= scale
    drawing.scale(scale, scale)

    x_offset = x + max((width - drawing.width) / 2, 0)
    y_offset = y + max((height - drawing.height) / 2, 0)
    renderPDF.draw(drawing, cnv, x_offset, y_offset)

    cnv.setFont("Helvetica", 8)
    cnv.drawCentredString(x + width / 2, y + 4, value)


def normalize_values(values):
    cleaned = [str(v).strip() for v in values if str(v).strip()]
    return cleaned


def draw_symbol(cnv, value, x, y, width, height, symbology):
    if symbology == "code128":
        draw_code128(cnv, value, x, y, width, height)
        return

    if symbology == "qr":
        draw_qr(cnv, value, x, y, width, height)
        return

    if symbology == "upc":
        draw_upc(cnv, value, x, y, width, height)
        return

    raise ValueError(f"Unsupported symbology: {symbology}")


def render_avery_3x10(cnv, values, symbology, fill_sheet):
    page_w, page_h = letter
    margin_left = 0.1875 * POINTS_PER_INCH
    margin_top = 0.5 * POINTS_PER_INCH
    cols, rows = 3, 10
    cell_w = 2.625 * POINTS_PER_INCH
    cell_h = 1.0 * POINTS_PER_INCH
    gap_x = 0.125 * POINTS_PER_INCH
    gap_y = 0.0

    values = values[:30]
    if fill_sheet and len(values) == 1:
        values = values * 30

    total_cells = cols * rows
    if len(values) < total_cells:
        values = values + [""] * (total_cells - len(values))

    for i in range(total_cells):
        row = floor(i / cols)
        col = i % cols
        x = margin_left + col * (cell_w + gap_x)
        y = page_h - margin_top - ((row + 1) * cell_h) - (row * gap_y)

        value = values[i]
        if not value:
            continue

        cnv.setLineWidth(0.25)
        cnv.rect(x, y, cell_w, cell_h)
        draw_symbol(cnv, value, x + 5, y + 2, cell_w - 10, cell_h - 4, symbology)


def render_single_small(cnv, values, symbology):
    page_w = 2.4 * POINTS_PER_INCH
    page_h = 1.1 * POINTS_PER_INCH
    value = values[0]
    draw_symbol(cnv, value, 3, 2, page_w - 6, page_h - 4, symbology)


def validate_upc(values):
    for value in values:
        if not value.isdigit() or len(value) not in (11, 12):
            raise ValueError("UPC values must be 11 or 12 digits")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    with open(args.input, "r", encoding="utf-8") as f:
        spec = json.load(f)

    template = spec.get("template", "")
    symbology = spec.get("symbology", "code128")
    values = normalize_values(spec.get("values", []))
    fill_sheet = bool(spec.get("fillSheet", True))

    if not values:
        raise ValueError("At least one barcode value is required")

    if symbology not in ("code128", "qr", "upc"):
        raise ValueError("Symbology must be code128, qr, or upc")

    if symbology == "upc":
        validate_upc(values)

    if template == "single_2_4x1_1":
        size = (2.4 * POINTS_PER_INCH, 1.1 * POINTS_PER_INCH)
        cnv = canvas.Canvas(args.output, pagesize=size)
        render_single_small(cnv, values, symbology)
        cnv.showPage()
        cnv.save()
        return

    if template == "avery_3x10":
        cnv = canvas.Canvas(args.output, pagesize=letter)
        render_avery_3x10(cnv, values, symbology, fill_sheet)
        cnv.showPage()
        cnv.save()
        return

    raise ValueError(f"Unsupported template: {template}")


if __name__ == "__main__":
    main()
