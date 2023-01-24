const align4 = (n) => (n + 3) & ~3;

const limit32 = (n) => (n & 0xFFFFFFFF) >>> 0;

/**
 * @param {Buffer} buf
 */
function tableChecksum(buf) {
    if (buf.length % 4 != 0) {
        throw 'bad buffer size';
    }
    let sum = 0;
    for (let i = 0; i < buf.length; i += 4) {
        sum = limit32(sum + buf.readUint32BE(i));
    }
    return sum;
}

/**
 * @param {Buffer} buf
 */
export const ttcUnpack = (buf, callback) => {
    if (buf.toString('ascii', 0, 4) !== 'ttcf') {
        return false;
    }

    const ttf_count = buf.readUInt32BE(8);
    for (let i = 0; i < ttf_count; i++) {
        const header_offset = buf.readUInt32BE(0x0C + i * 4);

        // Read tables & find head table
        const tables = buf.readUInt16BE(header_offset + 0x04);
        let table_length = 0, head_index = -1;
        for (let j = 0; j < tables; j++) {
            const offset = header_offset + 0x0C + j * 0x10;
            table_length += align4(buf.readUInt32BE(offset + 0x0C));

            if (head_index === -1 && buf.toString('ascii', offset, offset + 4) === 'head') {
                head_index = j;
            }
        }

        const header_length = 0x0C + tables * 0x10;

        const ttf = Buffer.alloc(header_length + table_length);
        buf.copy(ttf, 0, header_offset, header_offset + header_length);

        // Copy tables
        let head_offset = 0;
        for (let j = 0, ptr = header_length; j < tables; j++) {
            if (head_index === j) {
                head_offset = ptr + 8;
            }
            ttf.writeUInt32BE(ptr, 0x0C + 8 + j * 0x10);

            const offset = buf.readUInt32BE(header_offset + 0x0C + 8 + j * 0x10);
            const length = buf.readUInt32BE(header_offset + 0x0C + 0x0C + j * 0x10);

            buf.copy(ttf, ptr, offset, offset + length);
            ptr += align4(length);
        }

        // Fix checksum
        // https://learn.microsoft.com/en-us/typography/opentype/otspec182/otff#calculating-checksums
        if (head_offset) {
            ttf.writeUint32BE(0, head_offset);
            ttf.writeUint32BE(limit32(0xB1B0AFBA - tableChecksum(ttf)), head_offset);
        } else {
            console.warn('\tUnable to find checkSumAdjustment (head table) for ttf!');
        }

        callback(ttf, i);
    }
    return true;
}
