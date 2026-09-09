import cv2
import numpy as np


def build_overlay(image, col_x_frac, row_y_top_per_col, row_y_bot_per_col):
    h, w = image.shape[:2]
    vis = image.copy()
    top_pts = np.array([[int(xf * w), int(row_y_top_per_col[i] * h)] for i, xf in enumerate(col_x_frac)])
    bot_pts = np.array([[int(xf * w), int(row_y_bot_per_col[i] * h)] for i, xf in enumerate(col_x_frac)])
    cv2.polylines(vis, [top_pts], False, (255, 0, 0), 3)
    cv2.polylines(vis, [bot_pts], False, (255, 0, 0), 3)
    for i, xf in enumerate(col_x_frac):
        x = int(xf * w)
        y0 = int(row_y_top_per_col[i] * h)
        y1 = int(row_y_bot_per_col[i] * h)
        thick = 3 if i % 6 == 0 else 1
        color = (0, 200, 0) if i % 6 == 0 else (0, 200, 255)
        cv2.line(vis, (x, y0), (x, y1), color, thick)

    # crop tampilan ke sekitar area grid saja (biar hemat ukuran & fokus)
    y_top_min = min(row_y_top_per_col) * h
    y_bot_max = max(row_y_bot_per_col) * h
    margin = (y_bot_max - y_top_min) * 3
    y0c = max(0, int(y_top_min - margin))
    y1c = min(h, int(y_bot_max + margin))
    return vis[y0c:y1c, :]